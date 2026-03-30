/**
 * OAuth CoolKit: login-url | token
 * env: EWELINK_APP_ID, EWELINK_APP_SECRET
 *
 * login-url stdin: { "redirectUrl", "state" }
 * token stdin: { "redirectUrl", "code", "region?" }
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

async function cmdLoginUrl(input) {
  const client = new eWeLink.WebAPI({
    appId: process.env.EWELINK_APP_ID,
    appSecret: process.env.EWELINK_APP_SECRET,
    region: "eu",
    logObj: silentLog,
  });
  const url = client.oauth.createLoginUrl({
    redirectUrl: input.redirectUrl,
    state: input.state,
  });
  process.stdout.write(JSON.stringify({ ok: true, url }));
}

async function cmdToken(input) {
  let region = input.region || "eu";
  const tried = new Set();
  for (let i = 0; i < 8; i++) {
    if (tried.has(region)) break;
    tried.add(region);
    const client = new eWeLink.WebAPI({
      appId: process.env.EWELINK_APP_ID,
      appSecret: process.env.EWELINK_APP_SECRET,
      region,
      logObj: silentLog,
    });
    let res;
    try {
      res = await client.oauth.getToken({
        region,
        redirectUrl: input.redirectUrl,
        code: input.code,
      });
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
      const d = res.data;
      const at = d.at ?? d.accessToken;
      const rt = d.rt ?? d.refreshToken;
      const email =
        d.user?.email ||
        d.user?.phoneNumber ||
        null;
      process.stdout.write(
        JSON.stringify({
          ok: true,
          at,
          rt,
          apikey: d.user?.apikey ?? null,
          region: d.region || region,
          email,
        })
      );
      return;
    }
    if (res.error === 10004 && res.data?.region) {
      region = res.data.region;
      continue;
    }
    console.error(
      JSON.stringify({
        ok: false,
        error: res.error,
        msg: res.msg || res.message || "oauth token failed",
        data: res.data,
      })
    );
    process.exit(1);
  }
  console.error(
    JSON.stringify({ ok: false, error: "region_loop", msg: "OAuth token: region redirect failed" })
  );
  process.exit(1);
}

async function main() {
  const appId = process.env.EWELINK_APP_ID;
  const appSecret = process.env.EWELINK_APP_SECRET;
  if (!appId || !appSecret) {
    console.error(
      JSON.stringify({
        ok: false,
        error: "config",
        msg: "EWELINK_APP_ID and EWELINK_APP_SECRET required",
      })
    );
    process.exit(1);
  }

  const cmd = process.argv[2];
  const raw = await readStdin();
  let input = {};
  try {
    input = JSON.parse(raw || "{}");
  } catch {
    console.error(JSON.stringify({ ok: false, error: "parse", msg: "Invalid JSON" }));
    process.exit(1);
  }

  if (cmd === "login-url") {
    if (!input.redirectUrl || !input.state) {
      console.error(JSON.stringify({ ok: false, error: "validation", msg: "redirectUrl and state required" }));
      process.exit(1);
    }
    await cmdLoginUrl(input);
    return;
  }
  if (cmd === "token") {
    if (!input.redirectUrl || !input.code) {
      console.error(JSON.stringify({ ok: false, error: "validation", msg: "redirectUrl and code required" }));
      process.exit(1);
    }
    await cmdToken(input);
    return;
  }
  console.error(JSON.stringify({ ok: false, error: "usage", msg: "node oauth.mjs login-url|token < stdin.json" }));
  process.exit(1);
}

main();
