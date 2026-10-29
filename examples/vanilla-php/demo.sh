#!/usr/bin/env bash
set -euo pipefail

# Starts the mock upstream API and the BFF demo, runs the smoke test, then
# keeps both servers alive for manual browser testing. Ctrl-C stops everything.

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOCK_PORT="${MOCK_PORT:-8081}"
BFF_PORT="${BFF_PORT:-8080}"
BASE="http://127.0.0.1:${BFF_PORT}/de-bff/emergency"
STATE_FILE="${TMPDIR:-/tmp}/de-bff-example-state.json"
WHATSAPP_LOG="${TMPDIR:-/tmp}/de-bff-example-whatsapp.log"
MOCK_LOG="${TMPDIR:-/tmp}/de-bff-mock.log"
BFF_LOG="${TMPDIR:-/tmp}/de-bff-bff.log"
MOCK_PID=""
BFF_PID=""

cleanup() {
  [ -n "$BFF_PID" ] && kill "$BFF_PID" 2>/dev/null || true
  [ -n "$MOCK_PID" ] && kill "$MOCK_PID" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

command -v php >/dev/null || { echo "php is required" >&2; exit 1; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 1; }

if [ ! -d "$DIR/vendor" ]; then
  echo "Installing example dependencies…"
  composer install --no-interaction --prefer-dist --working-dir="$DIR"
fi

rm -f "$STATE_FILE" "$WHATSAPP_LOG"

echo "Starting mock API on :${MOCK_PORT}…"
EMERGENCY_BFF_API_ORIGIN="http://127.0.0.1:${MOCK_PORT}" \
  php -S "127.0.0.1:${MOCK_PORT}" "$DIR/mock-api.php" >"$MOCK_LOG" 2>&1 &
MOCK_PID=$!

echo "Starting BFF on :${BFF_PORT}…"
EMERGENCY_BFF_API_ORIGIN="http://127.0.0.1:${MOCK_PORT}" \
  php -S "127.0.0.1:${BFF_PORT}" -t "$DIR/public" "$DIR/public/index.php" >"$BFF_LOG" 2>&1 &
BFF_PID=$!

for _ in $(seq 1 50); do
  if curl -fsS "$BASE/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done

if ! curl -fsS "$BASE/health" >/dev/null 2>&1; then
  echo "BFF did not become healthy. Logs:" >&2
  echo "--- $BFF_LOG ---" >&2
  tail -20 "$BFF_LOG" >&2 || true
  echo "--- $MOCK_LOG ---" >&2
  tail -20 "$MOCK_LOG" >&2 || true
  exit 1
fi

echo
echo "Demo:  http://127.0.0.1:${BFF_PORT}"
echo "Mock:  http://127.0.0.1:${MOCK_PORT}"
echo "Logs:  $BFF_LOG and $MOCK_LOG"
echo "WhatsApp payloads: $WHATSAPP_LOG"
echo
BASE_URL="$BASE" "$DIR/smoke.sh"
echo
echo "Servers are running. Open the demo URL, or press Ctrl-C to stop."
wait
