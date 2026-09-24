#!/usr/bin/env bash
#
# Bring up the tester demo: built bundle from the pinned `demo` worktree,
# its own API, and a Cloudflare quick tunnel.
#
# Deliberately NOT the dev server. See docs/demo-tunnel.md — testers used to
# watch fields move under them because the tunnel pointed at the working tree.
#
set -euo pipefail

MAIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEMO="$MAIN/../biztrack-demo"
DB="$MAIN/api/database/database.sqlite"
LOGS="${TMPDIR:-/tmp}/biztrack-demo"
mkdir -p "$LOGS"

[ -d "$DEMO" ] || { echo "No demo worktree. Run: git worktree add ../biztrack-demo demo" >&2; exit 1; }

echo "Building the bundle testers will see…"
( cd "$DEMO/web" && npm run build >"$LOGS/build.log" 2>&1 ) || { tail -20 "$LOGS/build.log"; exit 1; }

# APP_DEBUG stays false: a stack trace on a public URL leaks paths and config.
( cd "$DEMO/api" && DB_DATABASE="$DB" APP_DEBUG=false nohup php artisan serve --port=8082 >"$LOGS/api.log" 2>&1 & )
# --host 127.0.0.1 is load-bearing, not tidiness. Left to itself `vite preview`
# binds [::1] only, while cloudflared dials the origin over IPv4. The tunnel
# then comes up, registers, prints a URL, and answers every request with a 502
# — all while `curl http://[::1]:5180` locally returns 200. It reads as a
# broken deploy and is a missing address family.
( cd "$DEMO/web" && VITE_API_TARGET=http://localhost:8082 nohup npx vite preview --port 5180 --strictPort --host 127.0.0.1 >"$LOGS/web.log" 2>&1 & )

# The queue worker. Owner e-mails are queued (App\Jobs\SendOwnerUpdateEmail),
# and without a worker they sit in the `jobs` table and nothing is ever sent —
# the in-app notice appears, the e-mail silently does not. Same database and
# same code as the API above, so it drains exactly what that API queues.
# --name is how demo-down.sh and demo-deploy.sh find this process again; one
# worker only, so a stale one is stopped first. See docs/email-setup.md.
pkill -f 'queue:work --name=biztrack-demo' 2>/dev/null || true
( cd "$DEMO/api" && DB_DATABASE="$DB" APP_DEBUG=false nohup php artisan queue:work --name=biztrack-demo --sleep=3 >"$LOGS/queue.log" 2>&1 & )

for _ in $(seq 1 30); do curl -sf -o /dev/null http://localhost:5180/ && break; sleep 1; done

# Plumber has no auth of its own; anything that reaches it can read the register.
if lsof -nP -iTCP:8787 -sTCP:LISTEN 2>/dev/null | grep -q '0\.0\.0\.0:8787'; then
  echo "REFUSING: plumber is bound to 0.0.0.0. Bind it to 127.0.0.1 first." >&2
  exit 1
fi

rm -f "$LOGS/tunnel.log"
# 127.0.0.1, not localhost: demo-deploy.sh finds the live tunnel by reading this
# command line back out of `ps`, so the two scripts have to spell the loopback
# the same way. Spelled differently, a tunnel raised here was invisible to the
# next deploy, which then exited silently under `set -euo pipefail`.
nohup cloudflared tunnel --url http://127.0.0.1:5180 >"$LOGS/tunnel.log" 2>&1 &

for _ in $(seq 1 45); do
  URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOGS/tunnel.log" 2>/dev/null | head -1) || true
  [ -n "${URL:-}" ] && break
  sleep 1
done

echo
echo "  Testers:  ${URL:-<not ready — see $LOGS/tunnel.log>}"
echo "  Serving:  $DEMO (branch demo, built bundle — your edits cannot reach it)"
echo "  Logs:     $LOGS  (queue.log: owner e-mails)"
echo
echo "  Permits print a verify link, and owner e-mails a sign-in link, from"
echo "  FRONTEND_URL in api/.env. Set it to the URL above before a session."
