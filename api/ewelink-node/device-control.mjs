/**
 * Вкл/выкл устройства (розетка и т.д.): stdin { at, region, deviceId, itemType 1|2, on: boolean }
 * Использует setAllThingStatus; при ошибке повтор с другим itemType (своё / расшаренное).
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

async function trySetSwitch(client, deviceId, itemType, on) {
  const params = { switch: on ? "on" : "off" };
  return await client.device.setAllThingStatus({
    thingList: [
      {
        type: itemType,
        id: String(deviceId),
        params,
      },
    ],
    timeout: 0,
  });
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
  let itemType = Number(input.itemType) === 2 ? 2 : 1;
  const on = Boolean(input.on);
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

  let res = await trySetSwitch(client, deviceId, itemType, on);
  let used = itemType;
  if (res.error !== 0) {
    const alt = itemType === 1 ? 2 : 1;
    res = await trySetSwitch(client, deviceId, alt, on);
    used = alt;
  }

  if (res.error !== 0) {
    console.error(
      JSON.stringify({
        ok: false,
        error: res.error,
        msg: res.msg || "setAllThingStatus failed",
        data: res.data,
      })
    );
    process.exit(1);
  }

  process.stdout.write(JSON.stringify({ ok: true, itemTypeUsed: used }));
}

main().catch((e) => {
  console.error(JSON.stringify({ ok: false, error: "exception", msg: e.message || String(e) }));
  process.exit(1);
});
