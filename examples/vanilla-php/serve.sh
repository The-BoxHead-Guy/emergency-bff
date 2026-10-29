#!/usr/bin/env bash
set -euo pipefail

# Runs the BFF against the real upstream configured in .env (no mock).
# Start the DataEmergencia API separately first.

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BFF_PORT="${BFF_PORT:-8080}"

if [ ! -f "$DIR/.env" ]; then
  echo "Missing $DIR/.env" >&2
  echo "Copy .env.example to .env and set the API origin and credentials." >&2
  exit 1
fi

if [ ! -d "$DIR/vendor" ]; then
  echo "Installing example dependencies…"
  composer install --no-interaction --prefer-dist --working-dir="$DIR"
fi

ORIGIN="$(grep -E '^EMERGENCY_BFF_API_ORIGIN=' "$DIR/.env" | head -1 | cut -d= -f2- || true)"
echo "Upstream: ${ORIGIN:-unset}"
echo "BFF:      http://127.0.0.1:${BFF_PORT}"
echo
echo "Real mode: activation sends real WhatsApp messages and creates"
echo "real emergency state. Use the mock (demo.sh) unless this is intended."
echo

exec php -S "127.0.0.1:${BFF_PORT}" -t "$DIR/public" "$DIR/public/index.php"
