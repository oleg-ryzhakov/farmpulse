#!/usr/bin/env bash
#
# FarmPulse sidecar — send stats to FarmPulse worker API; execute commands from responses.
# Does not read or modify Hive OS HIVE_HOST_URL / hive-agent configuration.
#
#   ./sidecar.sh           — один цикл (для systemd)
#   ./sidecar.sh trace     — только просмотр ответа; reboot/exec не запускать (для отладки)
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

# В journal не всегда попадает stderr oneshot-сервиса — дублируем в syslog.
sylog() {
  if command -v logger >/dev/null 2>&1; then
    logger -t "$LOG_TAG" "$@"
  fi
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

if [ "$TRACE" != "1" ]; then
  sylog "tick start farm_id=${FARM_ID}"
fi

run_command_from_json() {
  printf '%s' "$1" | python3 -u <<'PY'
import json
import os
import shutil
import subprocess
import sys

def log_err(msg):
    line = "[farmpulse-sidecar] " + msg
    print(line, file=sys.stderr, flush=True)
    try:
        subprocess.run(
            ["logger", "-t", "farmpulse-sidecar", msg[:1800]],
            timeout=5,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        )
    except Exception:
        pass

def spawn_detach(argv):
    try:
        subprocess.Popen(
            argv,
            start_new_session=True,
            close_fds=True,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        return True
    except OSError as ex:
        log_err("spawn failed: " + str(ex))
        return False

def log_reboot(msg):
    for path in ("/tmp/farmpulse-reboot.log", "/var/log/farmpulse-reboot.log"):
        try:
            with open(path, "a", encoding="utf-8") as f:
                f.write(msg + "\n")
        except Exception:
            pass
    log_err(msg)

def do_reboot_from_server():
    log_reboot("--- do_reboot_from_server start ---")
    # 1) Hive OS — только так
    if os.path.isfile("/hive/sbin/sreboot"):
        if spawn_detach(["/hive/sbin/sreboot"]):
            log_reboot("spawned /hive/sbin/sreboot")
            return
    # 2) Обычный Linux / VM: тот же путь, что «sudo /sbin/reboot» (не spawn — замена процесса)
    for p in ("/sbin/reboot", "/usr/sbin/reboot"):
        if os.path.isfile(p):
            log_reboot("execl " + p + " (same as manual /sbin/reboot)")
            try:
                os.execl(p, os.path.basename(p))
            except OSError as ex:
                log_reboot("execl failed: " + str(ex))
    # 3) loginctl — на старых systemd нет подкоманды reboot («Unknown command verb»)
    lc = shutil.which("loginctl")
    if lc:
        try:
            r = subprocess.run(
                [lc, "reboot"],
                timeout=60,
                capture_output=True,
                text=True,
            )
            err = (r.stderr or "") + (r.stdout or "")
            log_reboot("loginctl reboot rc=%s err=%s" % (r.returncode, err[:400]))
            if "Unknown command" in err or "Unknown command verb" in err:
                log_reboot("loginctl: skip (no reboot verb on this systemd)")
            elif r.returncode == 0:
                return
        except Exception as ex:
            log_reboot("loginctl: " + str(ex))
    dbus_send = shutil.which("dbus-send")
    if dbus_send:
        try:
            r = subprocess.run(
                [
                    dbus_send,
                    "--system",
                    "--print-reply",
                    "--dest=org.freedesktop.login1",
                    "/org/freedesktop/login1",
                    "org.freedesktop.login1.Manager.Reboot",
                    "boolean:false",
                ],
                timeout=30,
                capture_output=True,
                text=True,
            )
            log_reboot("dbus Reboot rc=%s out=%s" % (r.returncode, (r.stdout or "")[:300]))
            if r.returncode == 0:
                return
        except Exception as ex:
            log_reboot("dbus: " + str(ex))
    for sc in ("/usr/bin/systemctl", "/bin/systemctl", shutil.which("systemctl") or ""):
        if not sc or not os.path.isfile(sc):
            continue
        try:
            r = subprocess.run(
                [sc, "reboot"],
                timeout=90,
                capture_output=True,
                text=True,
            )
            log_reboot("systemctl reboot rc=%s stderr=%s" % (r.returncode, (r.stderr or "")[:500]))
            if r.returncode == 0:
                return
        except Exception as ex:
            log_reboot("systemctl: " + str(ex))
    alt = shutil.which("sreboot")
    if alt and os.path.isfile(alt):
        if spawn_detach([alt]):
            log_reboot("spawned " + alt)
            return
    for shut in ("/sbin/shutdown", "/usr/sbin/shutdown", shutil.which("shutdown") or ""):
        if not shut or not os.path.isfile(shut):
            continue
        if spawn_detach([shut, "-r", "now"]):
            log_reboot("spawned " + shut + " -r now")
            return
    log_reboot("do_reboot_from_server: all methods failed — see /tmp/farmpulse-reboot.log")

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
    do_reboot_from_server()
    sys.exit(0)
if cmd == "exec":
    ex = (res.get("exec") or "").strip()
    if not ex:
        sys.exit(0)
    if ex in ("sreboot", "/hive/sbin/sreboot") or ex.endswith("sreboot") or ex.endswith("/sreboot"):
        do_reboot_from_server()
        sys.exit(0)
    spawn_detach(["/bin/sh", "-c", ex])
    log_err("exec: spawned /bin/sh -c …")
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
  echo "FarmPulse trace — только просмотр"
  echo "В этом режиме reboot/exec из ответа НЕ запускаются (чтобы не перезагрузить риг при отладке)."
  echo "Обычный таймер farmpulse-sidecar выполняет команды автоматически."
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
  sylog "stats FAILED: ${RESP:0:200}"
  exit 0
fi

SUM_CMD="$(printf '%s' "$RESP" | python3 -c "import json,sys; d=json.load(sys.stdin); r=d.get('result') or {}; print(r.get('command','') or '')" 2>/dev/null || echo '?')"
SUM_EX="$(printf '%s' "$RESP" | python3 -c "import json,sys; d=json.load(sys.stdin); r=d.get('result') or {}; print(r.get('exec','') or '')" 2>/dev/null || echo '')"
sylog "stats ok command=${SUM_CMD:-} exec=${SUM_EX:0:80}"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) farm=${FARM_ID} cmd=${SUM_CMD:-} exec=${SUM_EX:-}" >> /var/log/farmpulse-sidecar.log 2>/dev/null || true

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
