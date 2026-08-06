#!/usr/bin/env bash
#
# Zero-downtime deploy of the tester demo.
#
# `demo-up.sh` tears the old stack down and then builds the new one, so there is
# a window — a couple of minutes, mostly the bundle build and the tunnel
# handshake — where the URL testers are holding is dead and the replacement does
# not exist yet. The client asked for the opposite order, in their words:
#
#   "when u deploy a tunnel, can u make it so that before the other tunnel thats
#    currently live goes down, the link for the tunnel should already be done?"
#
# So this brings the new stack up ALONGSIDE the old one on a second set of
# ports, waits until the new tunnel actually serves the new bundle, prints the
# URL, and only then stops the old stack. If anything fails before that point
# the new stack is torn down and the OLD one is left running untouched — a
# failed deploy must never be the thing that takes testers offline.
#
# Two port pairs, alternating. Whichever is live, this takes the other:
#
#   slot A   web 5180   api 8082
#   slot B   web 5181   api 8083
#
#   ./scripts/demo-deploy.sh          # swap to the other slot
#
set -euo pipefail

MAIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEMO="$MAIN/../biztrack-demo"
DB="$MAIN/api/database/database.sqlite"
LOGS="${TMPDIR:-/tmp}/biztrack-demo"
mkdir -p "$LOGS"

[ -d "$DEMO" ] || { echo "No demo worktree. Run: git worktree add ../biztrack-demo demo" >&2; exit 1; }

listening() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }

# Which slot is serving now? Default to B when nothing is up, so a cold start
# and a swap take the same path and the script has one behaviour, not two.
if listening 5180; then
  OLD_WEB=5180; OLD_API=8082; NEW_WEB=5181; NEW_API=8083; OLD_SLOT=A; NEW_SLOT=B
else
  OLD_WEB=5181; OLD_API=8083; NEW_WEB=5180; NEW_API=8082; OLD_SLOT=B; NEW_SLOT=A
fi
echo "Live slot: $OLD_SLOT (web $OLD_WEB) → deploying to slot $NEW_SLOT (web $NEW_WEB)"

# Plumber has no auth of its own; anything that reaches it can read the register.
if lsof -nP -iTCP:8787 -sTCP:LISTEN 2>/dev/null | grep -q '0\.0\.0\.0:8787'; then
  echo "REFUSING: plumber is bound to 0.0.0.0. Bind it to 127.0.0.1 first." >&2
  exit 1
fi

# Anything still on the target ports is a leftover from a half-finished run, not
# the live stack — the live one is the OTHER slot by definition.
lsof -ti tcp:$NEW_WEB tcp:$NEW_API 2>/dev/null | xargs kill -9 2>/dev/null || true

echo "Building the bundle testers will see…"
( cd "$DEMO/web" && npm run build >"$LOGS/build.log" 2>&1 ) || { tail -20 "$LOGS/build.log"; exit 1; }

# APP_DEBUG stays false: a stack trace on a public URL leaks paths and config.
( cd "$DEMO/api" && DB_DATABASE="$DB" APP_DEBUG=false nohup php artisan serve --port=$NEW_API >"$LOGS/api-$NEW_SLOT.log" 2>&1 & )
# --host 127.0.0.1 is load-bearing: left to itself `vite preview` binds [::1]
# only, cloudflared dials IPv4, and the tunnel 502s every request while a local
# IPv6 curl returns 200.
( cd "$DEMO/web" && VITE_API_TARGET=http://localhost:$NEW_API nohup npx vite preview --port $NEW_WEB --strictPort --host 127.0.0.1 >"$LOGS/web-$NEW_SLOT.log" 2>&1 & )

# The new stack has to answer locally before it is worth handing to cloudflared.
ok=""
for _ in $(seq 1 60); do
  curl -sf -o /dev/null "http://127.0.0.1:$NEW_WEB/" && { ok=1; break; }
  sleep 1
done
[ -n "$ok" ] || { echo "New stack never came up on :$NEW_WEB — leaving slot $OLD_SLOT live." >&2; tail -10 "$LOGS/web-$NEW_SLOT.log"; exit 1; }

rm -f "$LOGS/tunnel-$NEW_SLOT.log"
nohup cloudflared tunnel --url "http://127.0.0.1:$NEW_WEB" >"$LOGS/tunnel-$NEW_SLOT.log" 2>&1 &

URL=""
for _ in $(seq 1 60); do
  URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOGS/tunnel-$NEW_SLOT.log" 2>/dev/null | head -1) || true
  [ -n "${URL:-}" ] && break
  sleep 1
done
[ -n "${URL:-}" ] || { echo "No tunnel URL — leaving slot $OLD_SLOT live." >&2; exit 1; }

# A registered tunnel is not a reachable one: a fresh trycloudflare hostname
# takes a moment to resolve, and the old link must not be cut before the new one
# genuinely answers. This is the whole point of the script, so it is a hard gate
# rather than a courtesy wait.
served=""
for _ in $(seq 1 40); do
  [ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/" --max-time 10)" = "200" ] && { served=1; break; }
  sleep 5
done
if [ -z "$served" ]; then
  echo "New tunnel never served 200 ($URL). Tearing IT down; slot $OLD_SLOT stays live." >&2
  lsof -ti tcp:$NEW_WEB tcp:$NEW_API 2>/dev/null | xargs kill -9 2>/dev/null || true
  pkill -f "cloudflared tunnel --url http://127.0.0.1:$NEW_WEB" 2>/dev/null || true
  exit 1
fi

echo
echo "  NEW tunnel is live and serving:  $URL"
echo

# Only now is it safe. The old tunnel dies last, so there is no moment when
# neither URL works.
pkill -f "cloudflared tunnel --url http://127.0.0.1:$OLD_WEB" 2>/dev/null || true
pkill -f "cloudflared tunnel --url http://localhost:$OLD_WEB" 2>/dev/null || true
lsof -ti tcp:$OLD_WEB tcp:$OLD_API 2>/dev/null | xargs kill -9 2>/dev/null || true

echo "  Retired slot $OLD_SLOT (web $OLD_WEB, api $OLD_API)."
echo "  Serving:  $DEMO (branch demo, built bundle — your edits cannot reach it)"
echo "  Logs:     $LOGS"
echo
echo "  Permits print a verify link from FRONTEND_URL in api/.env."
echo "  Set it to the URL above before a session that involves printing permits."
