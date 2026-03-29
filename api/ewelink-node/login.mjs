/**
 * CLI: читает JSON из stdin, пишет JSON в stdout.
 * Переменные окружения: EWELINK_APP_ID, EWELINK_APP_SECRET (обязательны).
 *
 * Вход: { "account", "password", "area_code", "region?" }
 * Выход при успехе: { "ok": true, "region", "at", "rt", "apikey", "nickname" }
 * Выход при ошибке: код 1, { "ok": false, "error", "msg", "data" }
 */
import eWeLink from "ewelink-api-next";

const silentLog = {
  info: () => {},
  error: () => {},
};

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => {
      data += chunk;
    });
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

async function loginInRegion(region, account, password, areaCode) {
  const client = new eWeLink.WebAPI({
    appId: process.env.EWELINK_APP_ID,
    appSecret: process.env.EWELINK_APP_SECRET,
    region,
    logObj: silentLog,
  });
  return client.user.login({
    account,
    password,
    areaCode,
    lang: "en",
  });
}

async function main() {
  const appId = process.env.EWELINK_APP_ID;
  const appSecret = process.env.EWELINK_APP_SECRET;
  if (!appId || !appSecret) {
    console.error(
      JSON.stringify({
        ok: false,
        error: "config",
        msg: "Set EWELINK_APP_ID and EWELINK_APP_SECRET (CoolKit developer credentials).",
      })
    );
    process.exit(1);
  }

  let raw;
  try {
    raw = await readStdin();
  } catch (e) {
    console.error(JSON.stringify({ ok: false, error: "stdin", msg: String(e.message) }));
    process.exit(1);
  }

  let input;
  try {
    input = JSON.parse(raw || "{}");
  } catch {
    console.error(JSON.stringify({ ok: false, error: "parse", msg: "Invalid JSON input" }));
    process.exit(1);
  }

  const account = String(input.account || "").trim();
  const password = String(input.password || "");
  const areaCode = String(input.area_code || "+7").trim();
  if (!account || !password) {
    console.error(
      JSON.stringify({ ok: false, error: "validation", msg: "account and password are required" })
    );
    process.exit(1);
  }

  let region = String(input.region || "eu").slice(0, 8);
  const tried = new Set();
  const maxAttempts = 8;

  for (let i = 0; i < maxAttempts; i++) {
    if (tried.has(region)) {
      break;
    }
    tried.add(region);

    let res;
    try {
      res = await loginInRegion(region, account, password, areaCode);
    } catch (e) {
      console.error(
        JSON.stringify({
          ok: false,
          error: "network",
          msg: e && e.message ? e.message : String(e),
        })
      );
      process.exit(1);
    }

    if (res.error === 0 && res.data) {
      const out = {
        ok: true,
        region: res.data.region || region,
        at: res.data.at,
        rt: res.data.rt,
        apikey: res.data.user?.apikey || null,
        nickname: res.data.user?.nickname || null,
      };
      process.stdout.write(JSON.stringify(out));
      return;
    }

    if (res.error === 10004 && res.data?.region) {
      region = res.data.region;
      continue;
    }

    const errPayload = {
      ok: false,
      error: res.error,
      msg: res.msg || res.message || "login failed",
      data: res.data || null,
    };
    console.error(JSON.stringify(errPayload));
    process.exit(1);
  }

  console.error(
    JSON.stringify({
      ok: false,
      error: "region_loop",
      msg: "Could not resolve region after redirects",
    })
  );
  process.exit(1);
}

main();
