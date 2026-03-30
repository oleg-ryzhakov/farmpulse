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

/** Сначала текущий дом (как в приложении), иначе первый из списка. */
function extractFamilyId(data) {
  const d = data || {};
  if (d.currentFamilyId != null && String(d.currentFamilyId).trim() !== "") {
    return String(d.currentFamilyId);
  }
  const list = d.familyList || d.families || d.list || [];
  if (Array.isArray(list) && list.length > 0 && list[0].id) {
    return String(list[0].id);
  }
  return "";
}

function normalizeDeviceItemType(row) {
  const t = row?.itemType;
  if (t === 1 || t === 2) {
    return t;
  }
  const n = Number.parseInt(String(t ?? ""), 10);
  return Number.isNaN(n) ? -1 : n;
}

/** itemData: массив id или объектов с deviceid / deviceId (разные версии API). */
function extractDeviceIdsFromItemData(raw) {
  const out = [];
  const arr = Array.isArray(raw) ? raw : raw != null && raw !== "" ? [raw] : [];
  for (const x of arr) {
    if (x == null || x === "") {
      continue;
    }
    if (typeof x === "string" || typeof x === "number") {
      const s = String(x).trim();
      if (s) {
        out.push(s);
      }
      continue;
    }
    if (typeof x === "object") {
      const id = x.deviceid || x.deviceId || x.id || x.device_id;
      if (id) {
        out.push(String(id));
      }
    }
  }
  return out;
}

function buildThingRefs(rawList) {
  const thingRefs = [];
  for (const row of rawList) {
    const it = normalizeDeviceItemType(row);
    if (it !== 1 && it !== 2) {
      continue;
    }
    const ids = extractDeviceIdsFromItemData(row.itemData);
    for (const id of ids) {
      thingRefs.push({ itemType: it, id: String(id) });
    }
  }
  return thingRefs;
}

/**
 * Полный список thing с постраничной загрузкой (num:0 на части серверов даёт пустой ответ).
 * При ошибке первой страницы — возвращаем ошибку.
 */
async function fetchAllThingRows(client, familyIdMaybe) {
  let beginIndex = -99999;
  let thingList = [];
  let isContinue = true;
  let firstPage = true;

  while (isContinue) {
    const res = await client.device.getAllThings({
      lang: "en",
      familyId: familyIdMaybe,
      num: 30,
      beginIndex,
    });
    if (res.error !== 0) {
      if (firstPage) {
        return { ok: false, error: res.error, msg: res.msg || "getAllThings failed", data: res.data };
      }
      break;
    }
    firstPage = false;
    const page = res.data?.thingList || [];
    thingList = thingList.concat(page);
    const total = res.data?.total;
    if (page.length === 0) {
      isContinue = false;
    } else if (page.length < 30) {
      isContinue = false;
    } else if (typeof total === "number" && total > 0 && thingList.length >= total) {
      isContinue = false;
    } else {
      const last = page[page.length - 1];
      if (last && last.index != null) {
        beginIndex = last.index;
      } else {
        isContinue = false;
      }
    }
  }
  return { ok: true, thingList };
}

function parseDevicesFromGetThingsData(detailRes, chunkRefs) {
  const idToItemType = new Map(
    (chunkRefs || []).map((r) => [String(r.id), r.itemType === 2 ? 2 : 1])
  );
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
    const sid = String(deviceId);
    devices.push({
      deviceId: sid,
      name: dev.name || dev.deviceName || String(deviceId),
      online: dev.online !== false,
      productModel: dev.productModel || dev.extra?.model || "",
      itemType: idToItemType.get(sid) ?? 1,
    });
  }
  return devices;
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

  let rawList = [];
  let usedFamilyId = familyId;

  let page = await fetchAllThingRows(client, familyId || undefined);
  if (!page.ok) {
    console.error(JSON.stringify(page));
    process.exit(1);
  }
  rawList = page.thingList || [];

  if (rawList.length === 0 && familyId) {
    page = await fetchAllThingRows(client, undefined);
    if (!page.ok) {
      console.error(JSON.stringify(page));
      process.exit(1);
    }
    rawList = page.thingList || [];
    usedFamilyId = "";
  }

  const thingRefs = buildThingRefs(rawList);

  const meta = {
    thingRows: rawList.length,
    refs: thingRefs.length,
    familyId: usedFamilyId || null,
  };

  if (thingRefs.length === 0) {
    process.stdout.write(JSON.stringify({ ok: true, devices: [], familyId: usedFamilyId || null, _meta: meta }));
    return;
  }

  const allDevices = [];
  const chunkSize = 10;
  for (let i = 0; i < thingRefs.length; i += chunkSize) {
    const chunk = thingRefs.slice(i, i + chunkSize);
    const detailRes = await client.device.getThings({ thingList: chunk });
    if (detailRes.error !== 0) {
      console.error(
        JSON.stringify({
          ok: false,
          error: detailRes.error,
          msg: detailRes.msg || "getThings failed",
          data: detailRes.data,
          _meta: meta,
        })
      );
      process.exit(1);
    }
    allDevices.push(...parseDevicesFromGetThingsData(detailRes, chunk));
  }

  process.stdout.write(
    JSON.stringify({
      ok: true,
      devices: allDevices,
      familyId: usedFamilyId || null,
      _meta: { ...meta, devicesParsed: allDevices.length },
    })
  );
}

main().catch((e) => {
  console.error(JSON.stringify({ ok: false, error: "exception", msg: e.message || String(e) }));
  process.exit(1);
});
