#!/usr/bin/env bash
#
# First-time setup: write /etc/farmpulse.env, verify hello, enable systemd timer.
# Hive OS native API URL is never modified.
#
set -euo pipefail

ENV_FILE=/etc/farmpulse.env
STATE_DIR=/var/lib/farmpulse
HELLO_OK="$STATE_DIR/hello.ok"

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  echo "Run as root (e.g. sudo firstrun_farmpulse)." >&2
  exit 1
fi

mkdir -p "$STATE_DIR"

default_rig=""
if [ -f /hive-config/rig.conf ]; then
  default_rig=$(grep -E '^[[:space:]]*RIG_ID=' /hive-config/rig.conf | head -1 | sed 's/^[[:space:]]*RIG_ID=//;s/^"//;s/"$//') || true
fi

echo "FarmPulse — first run"
echo "---------------------"
echo "Use the same Farm ID and password you created in the FarmPulse web panel."
echo ""

read -r -p "FarmPulse base URL (https://your.domain) [required]: " FP_URL
FP_URL="${FP_URL%/}"
if [ -z "$FP_URL" ]; then
  echo "URL is required." >&2
  exit 1
fi

read -r -p "Farm / rig ID [${default_rig:-none}]: " RID
RID="${RID:-$default_rig}"
if [ -z "$RID" ]; then
  echo "Rig ID is required." >&2
  exit 1
fi

read -r -s -p "Farm password: " PASS
echo ""
if [ -z "$PASS" ]; then
  echo "Password is required." >&2
  exit 1
fi

read -r -p "Poll interval seconds [30]: " INTERVAL
INTERVAL="${INTERVAL:-30}"
if ! [[ "$INTERVAL" =~ ^[0-9]+$ ]] || [ "$INTERVAL" -lt 10 ]; then
  echo "Interval must be a number >= 10." >&2
  exit 1
fi

umask 077
cat >"$ENV_FILE" <<EOF
# FarmPulse sidecar only. Does not change Hive cloud (api.hiveos.farm / HIVE_HOST_URL).
FARMPULSE_URL=${FP_URL}
FARM_ID=${RID}
FARM_PASSWORD=${PASS}
FARMPULSE_INTERVAL_SEC=${INTERVAL}
EOF
chmod 600 "$ENV_FILE"

if ! python3 <<'PY'
import json
import subprocess
import urllib.error
import urllib.request
import urllib.parse
from pathlib import Path

def load_env(path):
    d = {}
    for line in Path(path).read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        d[k.strip()] = v.strip()
    return d

e = load_env("/etc/farmpulse.env")
base = e["FARMPULSE_URL"].rstrip("/")
farm_id = e["FARM_ID"]
pw = e["FARM_PASSWORD"]

gpu_nv = 0
gpu_amd = 0
try:
    r = subprocess.run(["nvidia-smi", "-L"], capture_output=True, text=True, timeout=30)
    if r.returncode == 0:
        gpu_nv = len([x for x in r.stdout.splitlines() if x.strip()])
except (FileNotFoundError, subprocess.TimeoutExpired):
    pass
try:
    r = subprocess.run(["rocm-smi", "-i"], capture_output=True, text=True, timeout=30)
    if r.returncode == 0:
        gpu_amd = len([x for x in r.stdout.splitlines() if x.strip().startswith("GPU")])
except (FileNotFoundError, subprocess.TimeoutExpired):
    pass

body = {
    "jsonrpc": "2.0",
    "id": 1,
    "method": "hello",
    "params": {
        "gpu_count_amd": gpu_amd,
        "gpu_count_nvidia": gpu_nv,
        "server_url": base,
    },
    "password": pw,
}
data = json.dumps(body).encode("utf-8")
q = urllib.parse.urlencode({"id_rig": farm_id, "method": "hello"})
url = base + "/api/worker/api.php?" + q
req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
try:
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = resp.read().decode("utf-8", errors="replace")
        if resp.status != 200:
            raise SystemExit("hello HTTP " + str(resp.status) + ": " + raw[:500])
        try:
            j = json.loads(raw)
        except json.JSONDecodeError:
            raise SystemExit("hello: not JSON: " + raw[:400]) from None
        if j.get("status") == "error":
            raise SystemExit("hello error: " + raw[:800])
        if j.get("error") is not None:
            raise SystemExit("hello error: " + raw[:800])
except urllib.error.HTTPError as ex:
    raise SystemExit("hello failed HTTP %s: %s" % (ex.code, ex.read().decode("utf-8", errors="replace")[:800])) from ex
PY
then
  echo "hello failed." >&2
  exit 1
fi

touch "$HELLO_OK"

cat >/etc/systemd/system/farmpulse-sidecar.timer <<EOF
[Unit]
Description=Run FarmPulse sidecar every ${INTERVAL}s

[Timer]
OnBootSec=1min
OnUnitActiveSec=${INTERVAL}s
Unit=farmpulse-sidecar.service
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable farmpulse-sidecar.timer
systemctl start farmpulse-sidecar.timer

echo ""
echo "Done. FarmPulse sidecar is active (timer). Hive OS cloud settings were not changed."
echo "Check: systemctl status farmpulse-sidecar.timer"
echo ""
