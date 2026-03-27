#!/usr/bin/env bash
#
# Периодически запускает «сухой» trace (stats без выполнения команд на риге).
# Удобно смотреть в реальном времени, что уходит на сервер и что приходит.
#
#   farmpulse-watch              # раз в 5 с
#   farmpulse-watch 10           # раз в 10 с
#   FARMPULSE_WATCH_SEC=2 farmpulse-watch
#
set -euo pipefail

SEC="${1:-${FARMPULSE_WATCH_SEC:-5}}"
if ! [[ "$SEC" =~ ^[0-9]+$ ]] || [ "$SEC" -lt 1 ]; then
  echo "Usage: farmpulse-watch [seconds]" >&2
  exit 1
fi

BIN="${FARMPULSE_SIDECAR:-/opt/farmpulse/bin/sidecar.sh}"
if [ ! -x "$BIN" ]; then
  echo "Not found: $BIN (set FARMPULSE_SIDECAR or install client)" >&2
  exit 1
fi

echo "FarmPulse watch: каждые ${SEC}s вызывается $BIN trace (Ctrl+C выход)"
echo ""

while true; do
  "$BIN" trace
  sleep "$SEC"
done
