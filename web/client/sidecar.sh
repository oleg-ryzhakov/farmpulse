#!/usr/bin/env bash
#
# FarmPulse sidecar — send stats to FarmPulse worker API; execute commands from responses.
# Does not read or modify Hive OS HIVE_HOST_URL / hive-agent configuration.
#
#   ./sidecar.sh           — один цикл (для systemd)
#   ./sidecar.sh trace     — один запрос: наглядный вывод, команды НЕ выполняются
#   FARMPULSE_DEBUG=1      — при обычном запуске писать краткий лог в stderr (для journal)
#
set -u

ENV_FILE=/etc/farmpulse.env
LOG_TAG=farmpulse-sidecar

TRACE=0
if [ "${1:-}" = "trace" ] || [ "${FARMPULSE_TRACE:-0}" = "1" ]; then
  TRACE=1
fi

DEBUG="${FARMPULSE_DEBUG:-0}"

log() {
  echo "[$LOG_TAG] $*" >&2
}

dbg() {
  if [ "$DEBUG" = "1" ]; then
    log "$@"
  fi
}

if [ ! -f "$ENV_FILE" ]; then
  log "Missing $ENV_FILE — run firstrun_farmpulse first."
  exit 0
fi

# shellcheck source=/dev/null
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

FARMPULSE_URL="${FARMPULSE_URL:-}"
FARM_ID="${FARM_ID:-}"
FARM_PASSWORD="${FARM_PASSWORD:-}"

if [ -z "$FARMPULSE_URL" ] || [ -z "$FARM_ID" ]; then
  log "FARMPULSE_URL and FARM_ID must be set in $ENV_FILE"
  exit 0
fi

FARMPULSE_URL="${FARMPULSE_URL%/}"

run_command_from_json() {
  printf '%s' "$1" | python3 <<'PY'
import json
import os
import shutil
import subprocess
import sys

raw = sys.stdin.read()
if not raw.strip():
    sys.exit(0)
try:
    d = json.loads(raw)
except json.JSONDecodeError:
    sys.exit(0)
res = d.get("result")
if not isinstance(res, dict):
    sys.exit(0)
cmd = res.get("command")
if cmd in (None, "OK"):
    sys.exit(0)
if cmd == "reboot":
    for path in ("/hive/sbin/sreboot",):
        if os.path.isfile(path):
            subprocess.run([path], check=False)
            sys.exit(0)
    alt = shutil.which("sreboot")
    if alt:
        subprocess.run([alt], check=False)
        sys.exit(0)
    for path in ("/sbin/reboot", "/usr/sbin/reboot"):
        if os.path.isfile(path):
            subprocess.run([path], check=False)
            sys.exit(0)
    sys.exit(0)
if cmd == "exec":
    ex = (res.get("exec") or "").strip()
    if not ex:
        sys.exit(0)
    if ex in ("sreboot", "/hive/sbin/sreboot") or ex.endswith("sreboot") or ex.endswith("/sreboot"):
        for path in ("/hive/sbin/sreboot", shutil.which("sreboot") or ""):
            if path and os.path.isfile(path):
                subprocess.run([path], check=False)
                sys.exit(0)
        for path in ("/sbin/reboot", "/usr/sbin/reboot"):
            if os.path.isfile(path):
                subprocess.run([path], check=False)
                sys.exit(0)
    subprocess.run(["/bin/sh", "-c", ex], check=False)
PY
}

STATS_BODY=$(ENV_FILE="$ENV_FILE" python3 <<'PY'
import json
import os
import re
import subprocess

def load_env(path):
    d = {}
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            d[k.strip()] = v.strip()
    return d

e = load_env(os.environ["ENV_FILE"])
pw = e.get("FARM_PASSWORD", "")

temps = [0]
try:
    r = subprocess.run(
        ["nvidia-smi", "--query-gpu=temperature.gpu", "--format=csv,noheader,nounits"],
        capture_output=True,
        text=True,
        timeout=45,
    )
    if r.returncode == 0:
        for line in r.stdout.splitlines():
            line = line.strip()
            if re.match(r"^-?\d+", line):
                try:
                    t = int(float(line.split()[0]))
                    if t > 0:
                        temps.append(t)
                except ValueError:
                    pass
except (FileNotFoundError, subprocess.TimeoutExpired):
    pass

body = {
    "jsonrpc": "2.0",
    "id": 1,
    "method": "stats",
    "params": {"temp": temps},
    "password": pw,
}
print(json.dumps(body))
PY
)

WORKER="${FARMPULSE_URL}/api/worker/api.php"
ENC_ID=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1], safe=''))" "$FARM_ID")
URL="${WORKER}?id_rig=${ENC_ID}&method=stats"

if [ "$TRACE" = "1" ]; then
  TMP=$(mktemp)
  trap 'rm -f "$TMP"' EXIT
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "FarmPulse trace  (команды из ответа не выполняются)"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "Время:    $(date '+%Y-%m-%d %H:%M:%S %Z')"
  echo "Ферма:    id_rig=$FARM_ID"
  echo "URL:      $URL"
  echo "Отправка: temps/GPU (params.temp) из тела JSON:"
  echo "$STATS_BODY" | python3 -c "import json,sys; d=json.load(sys.stdin); print(json.dumps(d.get('params',{}), indent=2, ensure_ascii=False))"
  echo ""
  HTTP=$(curl -sS --connect-timeout 20 --max-time 90 -o "$TMP" -w "%{http_code}" -X POST "$URL" \
    -H "Content-Type: application/json" \
    -d "$STATS_BODY") || HTTP="err"
  echo "HTTP:     $HTTP"
  echo "Ответ:"
  if [ -s "$TMP" ]; then
    cat "$TMP" | python3 -c "
import json, sys
raw = sys.stdin.read()
try:
    d = json.loads(raw)
except json.JSONDecodeError:
    print(raw[:4000])
    sys.exit(0)
r = d.get('result')
if isinstance(r, dict):
    cmd = r.get('command')
    print(json.dumps(d, indent=2, ensure_ascii=False)[:8000])
    print('---')
    print('Итог: result.command =', repr(cmd))
    if r.get('exec'):
        print('      result.exec   =', repr(r.get('exec')))
else:
    print(json.dumps(d, indent=2, ensure_ascii=False)[:8000])
" 2>/dev/null || cat "$TMP"
  else
    echo "(пустое тело)"
  fi
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  exit 0
fi

if ! RESP=$(curl -fsS --connect-timeout 20 --max-time 90 -X POST "$URL" \
  -H "Content-Type: application/json" \
  -d "$STATS_BODY" 2>&1); then
  log "stats request failed: $RESP"
  exit 0
fi

if [ "$DEBUG" = "1" ]; then
  echo "$RESP" | python3 <<'PY' >&2
import json, sys
try:
    d = json.load(sys.stdin)
    r = d.get("result") or {}
    print(f"[farmpulse-sidecar] result.command={r.get('command')!r} exec={r.get('exec')!r}", file=sys.stderr)
except Exception as ex:
    print(f"[farmpulse-sidecar] debug parse: {ex}", file=sys.stderr)
PY
fi

run_command_from_json "$RESP"
exit 0
