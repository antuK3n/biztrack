#!/usr/bin/env bash
#
# Raise a throwaway stack for the end-to-end suite.
#
# The dev stack on :5173 is proxied to the API holding real testers' data and
# is reachable through a public tunnel. A suite that submits an application
# against it writes junk into somebody's live filing, and there is no clean way
# to take that back — so the tests never point there.
#
# This copies the SQLite file and serves the copy from a second Laravel on
# :8081, with a second Vite on :5199 proxying to it. Both are disposable:
# delete the copy and start again whenever the data drifts.
#
#   web/  npm run e2e:stack     # leave running
#   web/  npm run e2e           # in another shell
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API="$ROOT/api"
LIVE_DB="$API/database/database.sqlite"
E2E_DB="$API/database/e2e.sqlite"
API_PORT=8081
WEB_PORT=5199

if [ ! -f "$LIVE_DB" ]; then
  echo "No database at $LIVE_DB — nothing to copy from." >&2
  exit 1
fi

echo "Copying the register to a throwaway database…"
cp "$LIVE_DB" "$E2E_DB"

cleanup() {
  echo
  echo "Stopping the test stack…"
  kill "${API_PID:-}" "${WEB_PID:-}" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# APP_DEBUG stays off even here: the suite should exercise the error pages a
# tester would actually meet, not the stack traces a developer would.
( cd "$API" && DB_DATABASE="$E2E_DB" APP_DEBUG=false php artisan serve --port="$API_PORT" ) &
API_PID=$!

( cd "$ROOT/web" && VITE_API_TARGET="http://localhost:$API_PORT" npx vite --port "$WEB_PORT" --strictPort ) &
WEB_PID=$!

echo
echo "  API  http://localhost:$API_PORT   (database/e2e.sqlite)"
echo "  App  http://localhost:$WEB_PORT   <- point E2E_BASE_URL here"
echo
echo "Ctrl-C to stop."
wait
