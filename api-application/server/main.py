"""
Сервис в памяти для мобильного клиента: снимок ферм + WebSocket при изменениях.
PHP worker пока пишет config.json отдельно; позже heartbeat можно дублировать сюда (POST /internal/heartbeat).
"""

from __future__ import annotations

import asyncio
import json
import os
import secrets
from datetime import datetime, timezone
from typing import Any, Optional

from fastapi import FastAPI, Header, HTTPException, Query, WebSocket
from pydantic import BaseModel, Field
from starlette.websockets import WebSocketDisconnect

API_KEY = os.environ.get("FARMPULSE_APP_API_KEY", "").strip()
BIND_HOST = os.environ.get("FARMPULSE_BIND_HOST", "127.0.0.1")
BIND_PORT = int(os.environ.get("FARMPULSE_BIND_PORT", "8000"))
BOOTSTRAP = os.environ.get("FARMPULSE_BOOTSTRAP_CONFIG", "").strip()

_farms: dict[str, dict[str, Any]] = {}
_lock = asyncio.Lock()
_ws_clients: set[WebSocket] = set()


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def _check_key(x_api_key: Optional[str], authorization: Optional[str], query_token: Optional[str]) -> None:
    if not API_KEY:
        raise HTTPException(status_code=503, detail="FARMPULSE_APP_API_KEY is not set on server")
    got: Optional[str] = None
    if x_api_key:
        got = x_api_key.strip()
    elif authorization and authorization.lower().startswith("bearer "):
        got = authorization[7:].strip()
    elif query_token:
        got = query_token.strip()
    if not got or not secrets.compare_digest(got.encode("utf-8"), API_KEY.encode("utf-8")):
        raise HTTPException(status_code=401, detail="Invalid or missing API key")


def _snapshot() -> dict[str, Any]:
    return {"status": "OK", "farms": list(_farms.values())}


async def _broadcast_snapshot() -> None:
    async with _lock:
        payload = {"type": "farms_snapshot", "data": _snapshot()}
    dead: list[WebSocket] = []
    for ws in list(_ws_clients):
        try:
            await ws.send_json(payload)
        except Exception:
            dead.append(ws)
    for ws in dead:
        _ws_clients.discard(ws)


def _farm_from_config(fid: str, raw: dict[str, Any]) -> dict[str, Any]:
    return {
        "id": str(fid),
        "name": raw.get("name"),
        "status": raw.get("status") or "offline",
        "last_seen_at": raw.get("last_seen_at"),
        "gpu_temps": list(raw.get("gpu_temps") or []),
        "gpu_count": int(raw.get("gpu_count") or 0),
    }


def _bootstrap_file(path: str) -> None:
    global _farms
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    farms = data.get("farms") or {}
    for fid, raw in farms.items():
        if isinstance(raw, dict):
            _farms[str(fid)] = _farm_from_config(str(fid), raw)


class HeartbeatBody(BaseModel):
    farm_id: str = Field(..., min_length=1)
    name: Optional[str] = None
    gpu_temps: Optional[list[float]] = None
    gpu_count: Optional[int] = None
    status: Optional[str] = None


app = FastAPI(title="FarmPulse app API", version="0.1.0")


@app.on_event("startup")
async def startup() -> None:
    if BOOTSTRAP and os.path.isfile(BOOTSTRAP):
        _bootstrap_file(BOOTSTRAP)


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok", "service": "farmpulse-app-api"}


@app.get("/farms")
async def get_farms(
    x_api_key: Optional[str] = Header(None, alias="X-Api-Key"),
    authorization: Optional[str] = Header(None),
) -> dict[str, Any]:
    _check_key(x_api_key, authorization, None)
    async with _lock:
        return _snapshot()


@app.post("/internal/heartbeat")
async def post_heartbeat(
    body: HeartbeatBody,
    x_api_key: Optional[str] = Header(None, alias="X-Api-Key"),
    authorization: Optional[str] = Header(None),
) -> dict[str, Any]:
    _check_key(x_api_key, authorization, None)
    async with _lock:
        fid = body.farm_id.strip()
        prev = _farms.get(fid, {"id": fid})
        row: dict[str, Any] = {
            "id": fid,
            "name": body.name if body.name is not None else prev.get("name"),
            "status": (body.status or "online").lower(),
            "last_seen_at": _utc_now_iso(),
            "gpu_temps": list(body.gpu_temps) if body.gpu_temps is not None else list(prev.get("gpu_temps") or []),
            "gpu_count": int(body.gpu_count) if body.gpu_count is not None else int(prev.get("gpu_count") or 0),
        }
        if row["gpu_temps"] and row["gpu_count"] == 0:
            row["gpu_count"] = len(row["gpu_temps"])
        _farms[fid] = row
    asyncio.create_task(_broadcast_snapshot())
    async with _lock:
        n = len(_farms)
    return {"status": "OK", "farm_id": fid, "farms_count": n}


@app.websocket("/ws")
async def ws_farms(websocket: WebSocket, token: Optional[str] = Query(None)) -> None:
    await websocket.accept()
    if not API_KEY:
        await websocket.send_json({"error": "server_misconfigured"})
        await websocket.close(code=1011)
        return
    if not token or not secrets.compare_digest(token.encode("utf-8"), API_KEY.encode("utf-8")):
        await websocket.send_json({"error": "unauthorized"})
        await websocket.close(code=1008)
        return
    _ws_clients.add(websocket)
    try:
        async with _lock:
            initial = {"type": "farms_snapshot", "data": _snapshot()}
        await websocket.send_json(initial)
        while True:
            await websocket.receive()
    except WebSocketDisconnect:
        pass
    finally:
        _ws_clients.discard(websocket)
