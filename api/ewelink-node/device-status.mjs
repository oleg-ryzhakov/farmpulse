/**
 * Статус розетки: stdin { at, region, deviceId }
 * GET /v2/device/thing/status — type 1 = устройство (не группа).
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

function pickSwitch(params) {
  if (params == null) {
    return null;
  }
  if (typeof params === "string") {
    try {
      const o = JSON.parse(params);
      return pickSwitch(o);
    } catch {
      return null;
    }
  }
  if (typeof params === "object" && !Array.isArray(params)) {
    const sw = params.switch ?? params.switches?.[0]?.switch;
    if (sw === "on" || sw === "off") {
      return sw;
    }
    if (params.switches && Array.isArray(params.switches) && params.switches[0]) {
      const s = params.switches[0].switch ?? params.switches[0].outlet;
      if (s === "on" || s === "off") {
        return s;
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

  const raw = res.data?.params ?? res.data;
  const sw = pickSwitch(raw);
  const online = res.data?.online !== false;

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
