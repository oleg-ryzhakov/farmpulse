/**
 * Список устройств eWeLink: stdin { "at", "region" }
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

function extractFamilyId(data) {
  const d = data || {};
  const list = d.familyList || d.families || d.list || [];
  if (Array.isArray(list) && list.length > 0 && list[0].id) {
    return String(list[0].id);
  }
  if (d.currentFamilyId) {
    return String(d.currentFamilyId);
  }
  return "";
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
  if (!at) {
    console.error(JSON.stringify({ ok: false, error: "validation", msg: "at required" }));
    process.exit(1);
  }

  const client = new eWeLink.WebAPI({
    appId,
    appSecret,
    region,
    logObj: silentLog,
  });
  client.at = at;

  const famRes = await client.home.getFamily({ lang: "en" });
  if (famRes.error !== 0) {
    console.error(
      JSON.stringify({
        ok: false,
        error: famRes.error,
        msg: famRes.msg || "getFamily failed",
        data: famRes.data,
      })
    );
    process.exit(1);
  }

  const familyId = extractFamilyId(famRes.data);
  const allRes = await client.device.getAllThings({
    lang: "en",
    familyId: familyId || undefined,
    num: 0,
    beginIndex: -9999999,
  });
  if (allRes.error !== 0) {
    console.error(
      JSON.stringify({
        ok: false,
        error: allRes.error,
        msg: allRes.msg || "getAllThings failed",
        data: allRes.data,
      })
    );
    process.exit(1);
  }

  const rawList = allRes.data?.thingList || [];
  const thingRefs = [];
  for (const row of rawList) {
    const it = row.itemType;
    if (it !== 1 && it !== 2) {
      continue;
    }
    const rawIds = row.itemData;
    const ids = Array.isArray(rawIds) ? rawIds : rawIds ? [rawIds] : [];
    for (const id of ids) {
      if (id) {
        thingRefs.push({ itemType: it, id: String(id) });
      }
    }
  }

  if (thingRefs.length === 0) {
    process.stdout.write(JSON.stringify({ ok: true, devices: [], familyId }));
    return;
  }

  const detailRes = await client.device.getThings({ thingList: thingRefs });
  if (detailRes.error !== 0) {
    console.error(
      JSON.stringify({
        ok: false,
        error: detailRes.error,
        msg: detailRes.msg || "getThings failed",
        data: detailRes.data,
      })
    );
    process.exit(1);
  }

  const devices = [];
  const dlist = detailRes.data?.thingList || detailRes.data?.deviceList || [];
  for (const item of dlist) {
    let dev = item.itemData;
    if (!dev || typeof dev !== "object" || Array.isArray(dev)) {
      dev = item;
    }
    if (!dev || typeof dev !== "object") {
      continue;
    }
    const deviceId = dev.deviceid || dev.deviceId || dev.id;
    if (!deviceId) {
      continue;
    }
    devices.push({
      deviceId: String(deviceId),
      name: dev.name || dev.deviceName || String(deviceId),
      online: dev.online !== false,
      productModel: dev.productModel || dev.extra?.model || "",
    });
  }

  process.stdout.write(JSON.stringify({ ok: true, devices, familyId }));
}

main().catch((e) => {
  console.error(JSON.stringify({ ok: false, error: "exception", msg: e.message || String(e) }));
  process.exit(1);
});
