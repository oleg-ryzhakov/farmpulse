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
  # Нельзя: printf ... | python <<'PY' — stdin у Python будет heredoc (код), не JSON.
  local _jf
  _jf=$(mktemp) || return 0
  printf '%s' "$1" > "$_jf"
  export FARMPULSE_JSON_PATH="$_jf"
  # shellcheck disable=SC2064
  trap 'rm -f "$_jf"; unset FARMPULSE_JSON_PATH' RETURN
  python3 -u <<'PY'
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
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True,
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
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True,
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
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True,
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

path = os.environ.get("FARMPULSE_JSON_PATH", "")
if not path:
    log_err("handler: FARMPULSE_JSON_PATH missing")
    sys.exit(1)
try:
    with open(path, encoding="utf-8") as _fp:
        raw = _fp.read()
except OSError as ex:
    log_err("handler: read json path: " + str(ex))
    sys.exit(1)
try:
    os.unlink(path)
except OSError:
    pass
if not raw.strip():
    sys.exit(0)
try:
    d = json.loads(raw)
except json.JSONDecodeError as ex:
    log_err("handler: JSON decode: " + str(ex))
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
rig_id = e.get("FARM_ID", "")

temps = [0]
fans = [0]
powers = [0]
jtemp = []
gpu_cards = []


def _nv_num(s):
    s = (s or "").strip()
    if not s or "[N/A]" in s.upper() or s.upper() == "N/A":
        return None
    try:
        return float(re.sub(r"[^0-9.+-]", "", s))
    except ValueError:
        return None


def _merge_gpu_detect_names(cards):
    paths = [
        os.environ.get("GPU_DETECT_JSON", ""),
        "/hive/gpu/gpu_detect.json",
        "/run/hive/gpu_detect.json",
    ]
    for path in paths:
        if not path or not os.path.isfile(path):
            continue
        try:
            with open(path, encoding="utf-8") as gf:
                d = json.load(gf)
            if not isinstance(d, list):
                continue
            nv = [x for x in d if isinstance(x, dict) and x.get("brand") == "nvidia"]
            for i, c in enumerate(cards):
                if i >= len(nv):
                    break
                nm = (nv[i].get("name") or nv[i].get("model") or "").strip()
                bus = str(nv[i].get("busid") or nv[i].get("bus_id") or "").strip()
                if nm and not (c.get("name") or "").strip():
                    c["name"] = nm
                if bus and not (c.get("bus_id") or "").strip():
                    c["bus_id"] = bus
        except (OSError, json.JSONDecodeError, TypeError, ValueError):
            continue
        break


def _nvidia_smi_gpu_cards(query_fields):
    import csv
    from io import StringIO

    r = subprocess.run(
        ["nvidia-smi", "--query-gpu=" + query_fields, "--format=csv,noheader,nounits"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        universal_newlines=True,
        timeout=45,
    )
    out = []
    if r.returncode != 0 or not (r.stdout or "").strip():
        return out
    for row in csv.reader(StringIO(r.stdout.strip())):
        out.append(row)
    return out


# 1) nvidia-smi: clocks.gr / clocks.mem лучше переносятся на ноутбуках, чем clocks.current.*
# Поля без vbios — на части драйверов vbios ломает CSV.
try:
    rows = _nvidia_smi_gpu_cards(
        "index,gpu_name,pci.bus_id,memory.total,clocks.gr,clocks.mem,power.draw,fan.speed,temperature.gpu"
    )
    if not rows:
        rows = _nvidia_smi_gpu_cards("index,gpu_name,pci.bus_id,temperature.gpu,fan.speed,power.draw")
    for row in rows:
        row = [x.strip() for x in row]
        if len(row) >= 9:
            idx, name, bus, memtot, cclk, mclk, pdraw, fn, tg = row[:9]
            vbios = ""
        elif len(row) >= 6:
            idx, name, bus, tg, fn, pdraw = row[:6]
            memtot, cclk, mclk, vbios = "", "", "", ""
        else:
            continue
        ti = int(float(_nv_num(idx) or 0))
        tgpu = _nv_num(tg)
        tfan = _nv_num(fn)
        tpw = _nv_num(pdraw)
        temps.append(int(tgpu) if tgpu is not None else 0)
        fans.append(int(min(100, max(0, tfan or 0))))
        powers.append(int(round(tpw)) if tpw is not None else 0)
        jtemp.append(0)
        mem_mb = ""
        mnv = _nv_num(memtot)
        if mnv and mnv > 0:
            mem_mb = str(int(mnv)) + " MiB"
        c_hz = _nv_num(cclk)
        m_hz = _nv_num(mclk)
        gpu_cards.append(
            {
                "index": ti,
                "name": name or "",
                "bus_id": bus or "",
                "vbios": vbios or "",
                "mem_total": mem_mb,
                "core_mhz": int(c_hz) if c_hz and c_hz > 0 else None,
                "mem_mhz": int(m_hz) if m_hz and m_hz > 0 else None,
                "temp": int(tgpu) if tgpu is not None else 0,
                "fan": int(min(100, max(0, tfan or 0))),
                "w": int(round(tpw)) if tpw is not None else 0,
                "brand": "nvidia",
            }
        )
    if gpu_cards:
        _merge_gpu_detect_names(gpu_cards)
except (FileNotFoundError, subprocess.TimeoutExpired, ValueError, ImportError):
    pass

# 2) Fallback: готовый JSON от Hive (agent.gpu-stats) — без заглушки в начале
if len(temps) <= 1:
    HIVE_GPU_STATS = "/run/hive/gpu-stats.json"
    if os.path.isfile(HIVE_GPU_STATS):
        try:
            with open(HIVE_GPU_STATS, encoding="utf-8") as hf:
                gs = json.load(hf)
            if isinstance(gs, dict) and isinstance(gs.get("temp"), list) and len(gs["temp"]) > 0:
                ta = gs["temp"]
                fa = gs.get("fan") if isinstance(gs.get("fan"), list) else []
                pa = gs.get("power") if isinstance(gs.get("power"), list) else []
                busids = gs.get("busids") if isinstance(gs.get("busids"), list) else []
                brands = gs.get("brand") if isinstance(gs.get("brand"), list) else []
                n = len(ta)
                temps = [0]
                fans = [0]
                powers = [0]
                jtemp = []
                gpu_cards = []
                for i in range(n):
                    tgpu = _nv_num(str(ta[i]))
                    tfan = _nv_num(str(fa[i])) if i < len(fa) else 0
                    tpw = _nv_num(str(pa[i])) if i < len(pa) else 0
                    temps.append(int(tgpu) if tgpu is not None else 0)
                    fans.append(int(min(100, max(0, tfan or 0))))
                    powers.append(int(round(tpw)) if tpw is not None else 0)
                    jtemp.append(0)
                    b = str(brands[i]) if i < len(brands) else ""
                    gpu_cards.append(
                        {
                            "index": i,
                            "name": "",
                            "bus_id": str(busids[i]) if i < len(busids) else "",
                            "vbios": "",
                            "mem_total": "",
                            "core_mhz": None,
                            "mem_mhz": None,
                            "temp": int(tgpu) if tgpu is not None else 0,
                            "fan": int(min(100, max(0, tfan or 0))),
                            "w": int(round(tpw)) if tpw is not None else 0,
                            "brand": b,
                        }
                    )
                if gpu_cards:
                    _merge_gpu_detect_names(gpu_cards)
        except (json.JSONDecodeError, TypeError, OSError, ValueError):
            pass

# 3) Последний fallback: только температура
if len(temps) <= 1:
    temps = [0]
    try:
        r = subprocess.run(
            ["nvidia-smi", "--query-gpu=temperature.gpu", "--format=csv,noheader,nounits"],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            universal_newlines=True,
            timeout=45,
        )
        if r.returncode == 0:
            for line in r.stdout.splitlines():
                line = line.strip()
                if re.match(r"^-?\d+", line):
                    try:
                        t = int(float(line.split()[0]))
                        temps.append(t)
                    except ValueError:
                        pass
    except (FileNotFoundError, subprocess.TimeoutExpired):
        pass
    if len(temps) > 1:
        fans = [0] * len(temps)
        powers = [0] * len(temps)

# Если есть температуры, но карточки не собрались — синтез + имена из gpu_detect (как в Hive UI)
if len(temps) > 1 and not gpu_cards:
    gpu_cards = []
    for i in range(1, len(temps)):
        gpu_cards.append(
            {
                "index": i - 1,
                "name": "",
                "bus_id": "",
                "vbios": "",
                "mem_total": "",
                "core_mhz": None,
                "mem_mhz": None,
                "temp": int(temps[i]) if temps[i] is not None else 0,
                "fan": int(fans[i]) if i < len(fans) else 0,
                "w": int(powers[i]) if i < len(powers) else 0,
                "brand": "nvidia",
            }
        )
    _merge_gpu_detect_names(gpu_cards)

if len(fans) != len(temps):
    fans = [0] * len(temps)
if len(powers) != len(temps):
    powers = [0] * len(temps)

mem = [0, 0]
try:
    r = subprocess.run(["free", "-m"], stdout=subprocess.PIPE, universal_newlines=True, timeout=5)
    if r.returncode == 0:
        for line in r.stdout.splitlines():
            if line.startswith("Mem"):
                w = line.split()
                if len(w) >= 7:
                    mem = [int(w[1]), int(w[6])]
                break
except (FileNotFoundError, subprocess.TimeoutExpired, ValueError):
    pass

df = ""
try:
    r = subprocess.run(
        ["df", "-h", "/"],
        stdout=subprocess.PIPE,
        universal_newlines=True,
        timeout=5,
    )
    if r.returncode == 0:
        lines = [x for x in r.stdout.strip().splitlines() if x.strip()]
        if len(lines) >= 2:
            df = lines[-1].split()[3].replace("%", "")
except (FileNotFoundError, subprocess.TimeoutExpired, IndexError):
    pass

cpuavg = ["0", "0", "0"]
try:
    with open("/proc/loadavg", encoding="utf-8") as f:
        la = f.read().split()
        if len(la) >= 3:
            cpuavg = [la[0], la[1], la[2]]
except OSError:
    pass

cputemp = [0]
try:
    import glob
    zones = sorted(glob.glob("/sys/class/thermal/thermal_zone*/temp"))
    tot = 0
    n = 0
    for z in zones[:4]:
        try:
            with open(z, encoding="utf-8") as tf:
                v = int(tf.read().strip())
                tot += v // 1000
                n += 1
        except (OSError, ValueError):
            continue
    if n:
        cputemp = [tot // n]
except Exception:
    pass

sys_uptime_sec = 0
try:
    with open("/proc/uptime", encoding="utf-8") as _up:
        sys_uptime_sec = int(float(_up.read().split()[0]))
except (OSError, ValueError, IndexError):
    pass

net_ips = []
try:
    r = subprocess.run(
        ["hostname", "-I"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        universal_newlines=True,
        timeout=5,
    )
    if r.returncode == 0 and r.stdout:
        net_ips = [x for x in r.stdout.strip().split() if x]
except (FileNotFoundError, subprocess.TimeoutExpired):
    pass

params = {
    "v": 2,
    "rig_id": rig_id,
    "passwd": pw,
    "temp": temps,
    "fan": fans,
    "power": powers,
    "df": df,
    "mem": mem,
    "cputemp": cputemp,
    "cpuavg": cpuavg,
    "sys_uptime_sec": sys_uptime_sec,
    "net_ips": net_ips,
}

if gpu_cards:
    params["gpu_cards"] = gpu_cards

if len(jtemp) == len(temps) - 1 and any(jtemp):
    params["jtemp"] = [0] + jtemp

try:
    with open("/run/hive/last_cmd_id", encoding="utf-8") as f:
        lc = f.read().strip()
        if lc.isdigit():
            params["last_cmd_id"] = int(lc)
except OSError:
    pass

try:
    with open("/run/hive/last_stat.json", encoding="utf-8") as f:
        agent = json.load(f)
    ap = agent.get("params") or {}
    for k, v in ap.items():
        if k.startswith("miner") or k.startswith("total_khs"):
            params[k] = v
        if k in ("miner_stats", "miner_stats2") or k.startswith("miner_stats"):
            params[k] = v
except (OSError, json.JSONDecodeError, TypeError):
    pass

body = {
    "jsonrpc": "2.0",
    "id": 1,
    "method": "stats",
    "params": params,
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
  printf '%s' "$RESP" | python3 -c "import json,sys; d=json.load(sys.stdin); r=d.get('result')or{}; print('[farmpulse-sidecar] result.command='+repr(r.get('command'))+' exec='+repr(r.get('exec')), file=sys.stderr)" || true
fi

run_command_from_json "$RESP"
exit 0
