/**
 * Статус розетки: stdin { at, region, deviceId }
 * GET /v2/device/thing/status — разбор params для Sonoff BASICR2 и др.
 */
import eWeLink from "ewelink-api-next";

const silentLog = { info: () => {}, error: () => {} };

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (c) => {
      data += c;
    });
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

function normalizeOnOff(val) {
  if (val == null) {
    return null;
  }
  if (typeof val === "boolean") {
    return val ? "on" : "off";
  }
  if (typeof val === "number" && !Number.isNaN(val)) {
    if (val === 1) {
      return "on";
    }
    if (val === 0) {
      return "off";
    }
  }
  const s = String(val).trim().toLowerCase();
  if (s === "on" || s === "1" || s === "true") {
    return "on";
  }
  if (s === "off" || s === "0" || s === "false") {
    return "off";
  }
  return null;
}

/** Параметры иногда приходят строкой вида switch=on&... */
function fromQueryLikeString(str) {
  if (typeof str !== "string" || str.indexOf("=") === -1) {
    return null;
  }
  const q = {};
  for (const part of str.split("&")) {
    const i = part.indexOf("=");
    if (i <= 0) {
      continue;
    }
    const k = decodeURIComponent(part.slice(0, i).trim());
    const v = decodeURIComponent(part.slice(i + 1).trim());
    q[k] = v;
  }
  return Object.keys(q).length ? q : null;
}

function pickSwitchFromFlatObject(obj) {
  if (!obj || typeof obj !== "object" || Array.isArray(obj)) {
    return null;
  }
  for (const key of ["switch", "outlet", "relay"]) {
    if (key in obj) {
      const n = normalizeOnOff(obj[key]);
      if (n) {
        return n;
      }
    }
  }
  if (Array.isArray(obj.switches)) {
    for (const row of obj.switches) {
      if (row && typeof row === "object") {
        const n = normalizeOnOff(row.switch ?? row.outlet ?? row.outlet_0);
        if (n) {
          return n;
        }
      }
    }
  }
  return null;
}

/**
 * Рекурсивный поиск switch / switches в ответе (вложенные params, itemData).
 */
function deepFindSwitch(obj, depth) {
  const d = depth ?? 0;
  if (d > 10 || obj == null) {
    return null;
  }
  if (typeof obj === "string") {
    const trimmed = obj.trim();
    if (trimmed.startsWith("{") || trimmed.startsWith("[")) {
      try {
        return deepFindSwitch(JSON.parse(trimmed), d + 1);
      } catch {
        /* ignore */
      }
    }
    const q = fromQueryLikeString(trimmed);
    if (q) {
      const n = pickSwitchFromFlatObject(q);
      if (n) {
        return n;
      }
    }
    return null;
  }
  if (Array.isArray(obj)) {
    for (const el of obj) {
      const n = deepFindSwitch(el, d + 1);
      if (n) {
        return n;
      }
    }
    return null;
  }
  if (typeof obj !== "object") {
    return null;
  }
  const direct = pickSwitchFromFlatObject(obj);
  if (direct) {
    return direct;
  }
  for (const k of ["params", "itemData", "state", "data", "status"]) {
    if (k in obj) {
      const n = deepFindSwitch(obj[k], d + 1);
      if (n) {
        return n;
      }
    }
  }
  return null;
}

async function main() {
  const appId = process.env.EWELINK_APP_ID;
  const appSecret = process.env.EWELINK_APP_SECRET;
  if (!appId || !appSecret) {
    console.error(JSON.stringify({ ok: false, error: "config", msg: "EWELINK_APP_ID/SECRET" }));
    process.exit(1);
  }
  let input;
  try {
    input = JSON.parse((await readStdin()) || "{}");
  } catch {
    console.error(JSON.stringify({ ok: false, error: "parse", msg: "Invalid JSON" }));
    process.exit(1);
  }
  const at = String(input.at || "");
  const region = String(input.region || "eu");
  const deviceId = String(input.deviceId || "").trim();
  if (!at || !deviceId) {
    console.error(JSON.stringify({ ok: false, error: "validation", msg: "at and deviceId required" }));
    process.exit(1);
  }

  const client = new eWeLink.WebAPI({
    appId,
    appSecret,
    region,
    logObj: silentLog,
  });
  client.at = at;

  const res = await client.device.getThingStatus({
    type: 1,
    id: deviceId,
    params: "",
  });

  if (res.error !== 0) {
    console.error(
      JSON.stringify({
        ok: false,
        error: res.error,
        msg: res.msg || "getThingStatus failed",
        data: res.data,
      })
    );
    process.exit(1);
  }

  const sw = deepFindSwitch(res.data);
  let online = true;
  if (res.data && typeof res.data === "object" && res.data.online === false) {
    online = false;
  }

  process.stdout.write(
    JSON.stringify({
      ok: true,
      switch: sw,
      online,
    })
  );
}

main().catch((e) => {
  console.error(JSON.stringify({ ok: false, error: "exception", msg: e.message || String(e) }));
  process.exit(1);
});
