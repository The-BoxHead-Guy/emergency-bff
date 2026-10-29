#!/usr/bin/env bash
set -euo pipefail

# End-to-end smoke test for a running vanilla example.
# Usage: BASE_URL=http://127.0.0.1:8080/de-bff/emergency ./smoke.sh

BASE="${BASE_URL:-http://127.0.0.1:8080/de-bff/emergency}"
JAR="$(mktemp)"
BODY_FILE="$(mktemp)"
trap 'rm -f "$JAR" "$BODY_FILE"' EXIT

STATUS=""
BODY=""
TOKEN=""
EMERGENCY_ID=""

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

assert_status() {
  [ "$STATUS" = "$1" ] || fail "expected HTTP $1, got $STATUS: $BODY"
}

assert_field() {
  local actual
  actual="$(json "$1")"
  [ "$actual" = "$2" ] || fail "expected $1=$2, got '$actual': $BODY"
}

json() {
  printf '%s' "$BODY" | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $path = explode(".", $argv[1]);
    foreach ($path as $key) {
        if (!is_array($data) || !array_key_exists($key, $data)) { exit(0); }
        $data = $data[$key];
    }
    if (is_bool($data)) { echo $data ? "true" : "false"; }
    elseif (is_scalar($data)) { echo $data; }
    else { echo json_encode($data); }
  ' "$1"
}

call() {
  local method="$1" path="$2" token="${3:-}" data="${4:-}"
  local args=(-sS -o "$BODY_FILE" -w '%{http_code}' -b "$JAR" -c "$JAR" -X "$method" "$BASE$path")

  [ -n "$token" ] && args+=(-H "X-CSRF-Token: $token")
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' -d "$data")

  STATUS="$(curl "${args[@]}")"
  BODY="$(cat "$BODY_FILE")"
}

pass() {
  echo "PASS: $*"
}

call GET /health
assert_status 200
assert_field status ok
pass "health"

call GET /config
assert_status 200
TOKEN="$(json csrf_token)"
[ "${#TOKEN}" -eq 64 ] || fail "expected a 64-character CSRF token, got '$TOKEN'"
pass "config exposes a CSRF token"

call POST /session "$TOKEN" '{"code":"bad"}'
assert_status 422
assert_field error.code invalid_code
pass "invalid format is rejected without an upstream profile fetch"

call POST /session "$TOKEN" '{"code":"ZZZZZZ"}'
assert_status 404
assert_field error.code inactive_code
pass "inactive code is rejected"

call POST /session "$TOKEN" '{"code":"DABC12"}'
assert_status 201
TOKEN="$(json csrf_token)"
assert_field code DABC12
pass "valid code starts a session"

call GET /current
assert_status 200
assert_field active false
pass "current starts idle"

call POST /simulacrum "$TOKEN" '{}'
assert_status 200
assert_field simulated true
pass "simulacrum sends test messages without state"

call POST /activate "$TOKEN" '{"geolocation":{"latitude":4.711,"longitude":-74.0721}}'
assert_status 201
EMERGENCY_ID="$(json emergency_id)"
[ -n "$EMERGENCY_ID" ] || fail "activation did not return an emergency id: $BODY"
assert_field responders.0.sent true
pass "activation creates $EMERGENCY_ID and marks the responder sent"

call GET /current
assert_status 200
assert_field active true
assert_field emergency.emergency_id "$EMERGENCY_ID"
pass "current resumes the active emergency"

call POST "/$EMERGENCY_ID/refresh" "$TOKEN" '{}'
assert_status 200
assert_field emergency.emergency_id "$EMERGENCY_ID"
pass "refresh returns the upstream emergency"

call DELETE "/$EMERGENCY_ID" "$TOKEN"
assert_status 200
assert_field deleted true
pass "abort deletes $EMERGENCY_ID"

call GET /current
assert_status 200
assert_field active false
pass "current is idle after abort"

echo "All smoke checks passed."
