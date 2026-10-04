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

# ── Naming a stack ───────────────────────────────────────────────────────────
#
# The defaults are the single stack this script has always raised, so
# `npm run e2e:stack` behaves exactly as before.
#
# E2E_SLOT gives a run its own database AND its own ports, which is what lets
# two suites be written at once without either seeing the other's writes. The
# database is copied per slot, so a test that submits an application in one
# slot is invisible in the next — which matters more than it sounds: these
# specs assert on counts, and a stray filing from a neighbouring run is
# indistinguishable from the product double-counting.
SLOT="${E2E_SLOT:-}"
E2E_DB="${E2E_DB:-$API/database/e2e${SLOT:+-$SLOT}.sqlite}"
API_PORT="${E2E_API_PORT:-8081}"
WEB_PORT="${E2E_WEB_PORT:-5199}"

if [ ! -f "$LIVE_DB" ]; then
  echo "No database at $LIVE_DB — nothing to copy from." >&2
  exit 1
fi

# ── Copying a WAL database ───────────────────────────────────────────────────
#
# `cp "$LIVE_DB" "$E2E_DB"` is what this used to be, and it is wrong for this
# database. The register runs in WAL mode: committed changes live in
# `database.sqlite-wal` until SQLite checkpoints them back, and that file was
# 3.9 MB when this was found. A plain `cp` takes the main file alone, so the
# throwaway stack came up holding the register as it stood at the last
# checkpoint — silently, and with no error anywhere.
#
# It cost an afternoon: two migrations applied to the live database were simply
# absent here, so a screen that worked on :5173 answered 500 on :5199 and the
# browser suite disagreed with the API suite about whether a column existed.
#
# `VACUUM INTO` writes one consistent file with the WAL folded in — SQLite's
# own answer to "give me a copy of this database". The target must not exist,
# hence the rm.
echo "Copying the register to a throwaway database…"

# ── Remove the old copy first, and check the new one ─────────────────────
#
# `cp` over a file another process still holds open does not always land on
# Windows. On 1 October 2026 this produced an E2E database the same SIZE as
# the register and containing ZERO TABLES — twice — because a previous
# stack's `php artisan serve` was still attached to it. The script said
# "Copying…", reported success and served an empty database.
#
# What that costs is the diagnosis, not the copy. Every spec fails in
# auth.setup with "could not sign in", which reads as a broken login, a bad
# password or an API that will not boot. The real fault — the file has no
# tables in it — is nowhere in the output, and the search starts in the
# wrong place.
#
# So: delete before copying, so a locked file fails loudly here rather than
# silently leaving yesterday's, and then COUNT THE TABLES. `users` is the
# one every session needs, which makes it the honest thing to test for.
rm -f "$E2E_DB" "$E2E_DB-wal" "$E2E_DB-shm"
php -r '
  $source = new PDO("sqlite:".$argv[1]);
  $source->exec("VACUUM INTO ".$source->quote($argv[2]));
' "$LIVE_DB" "$E2E_DB"

# Through PHP, not `sqlite3`: the CLI is not installed on every machine that
# runs this (it is absent on the one this was written on), and a check that
# reports "no tables" because the checker is missing would fail the stack
# every time -- which is worse than the fault it is looking for. PHP is
# already a hard dependency two lines below.
TABLES="$(php -r 'try { $d = new PDO("sqlite:" . $argv[1]); echo (int) $d->query("select count(*) from sqlite_master where type='"'"'table'"'"' and name='"'"'users'"'"'")->fetchColumn(); } catch (Throwable $e) { echo 0; }' "$E2E_DB" 2>/dev/null || echo 0)"

if [ "$TABLES" != "1" ]; then
  echo >&2
  echo "The copy at $E2E_DB has no 'users' table — it is empty or unreadable." >&2
  echo "A stale test stack is usually still holding the file. Stop any" >&2
  echo "'php artisan serve' on port $API_PORT, then run this again." >&2
  exit 1
fi

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

# --host 127.0.0.1 is load-bearing, and demo-up.sh carries the same note for
# the same reason. Left to itself Vite binds [::1] ONLY, so the dev server is
# reachable as localhost and invisible as 127.0.0.1.
#
# That cost a session. A stale Vite sat on [::1]:5199 while `lsof -iTCP:5199`
# in its IPv4 form and every `curl 127.0.0.1:5199` said the port was free — so
# restarting the stack failed with "port already in use" against a port that
# looked empty, and Playwright talked to a server that accepted nothing. The
# same suite ran 23/23 in 58s once it was cleared, having taken 1.8 hours and
# failed ten tests before. Binding both families makes the port answer to the
# name you check it with.
( cd "$ROOT/web" && VITE_API_TARGET="http://localhost:$API_PORT" npx vite --port "$WEB_PORT" --strictPort --host 127.0.0.1 ) &
WEB_PID=$!

echo
echo "  API  http://localhost:$API_PORT   (database/e2e.sqlite)"
echo "  App  http://localhost:$WEB_PORT   <- point E2E_BASE_URL here"
echo
echo "Ctrl-C to stop."
wait
