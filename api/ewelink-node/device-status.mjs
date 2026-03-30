/**
 * Статус розетки: stdin { at, region, deviceId, itemType? 1|2 }
 * 1) getThingStatus — часто BASICR2 / Sonoff возвращают ошибку или пустой разбор.
 * 2) fallback: getThings по deviceId (тот же путь, что и в devices.mjs) — там же лежит switch.
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

/**
 * Детали устройства через POST /v2/device/thing (getThings) — надёжнее для BASICR2.
 */
async function switchFromGetThings(client, deviceId, preferItemType) {
  const order = preferItemType === 2 ? [2, 1] : [1, 2];
  let online = true;
  for (const it of order) {
    const detailRes = await client.device.getThings({
      thingList: [{ itemType: it, id: String(deviceId) }],
    });
    if (detailRes.error !== 0) {
      continue;
    }
    const dlist = detailRes.data?.thingList || detailRes.data?.deviceList || [];
    for (const item of dlist) {
      let dev = item.itemData;
      if (!dev || typeof dev !== "object" || Array.isArray(dev)) {
        dev = item;
      }
      if (!dev || typeof dev !== "object") {
        continue;
      }
      const sw = pickSwitchFromFlatObject(dev) || deepFindSwitch(dev) || deepFindSwitch(item);
      if (dev.online === false) {
        online = false;
      }
      return { switch: sw, online };
    }
  }
  return { switch: null, online };
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
  const preferItemType = Number(input.itemType) === 2 ? 2 : 1;
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

  let sw = null;
  let online = true;

  const res = await client.device.getThingStatus({
    type: 1,
    id: deviceId,
    params: "",
  });
  if (res.error === 0) {
    sw = deepFindSwitch(res.data);
    if (res.data && typeof res.data === "object" && res.data.online === false) {
      online = false;
    }
  }

  if (sw == null) {
    const fromThings = await switchFromGetThings(client, deviceId, preferItemType);
    if (fromThings.switch != null) {
      sw = fromThings.switch;
    }
    if (fromThings.online === false) {
      online = false;
    }
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
