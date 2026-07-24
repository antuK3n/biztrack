#!/usr/bin/env bash
# dev.sh — run the whole BizTrack stack locally (API + web) with one command.
#   ./dev.sh            # starts both, Ctrl-C stops both
# API  → http://localhost:8080/api/v1   (Laravel, SQLite)
# Web  → http://localhost:5173          (Vite + React)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Prefer the CRAN-free system PHP; use the Framework/Homebrew whichever is on PATH.
API_PORT=8080
WEB_PORT=5173

echo "▶ Freeing ports…"
lsof -ti tcp:$API_PORT 2>/dev/null | xargs kill -9 2>/dev/null || true
lsof -ti tcp:$WEB_PORT 2>/dev/null | xargs kill -9 2>/dev/null || true

echo "▶ Ensuring DB is migrated + seeded…"
( cd "$ROOT/api" && php artisan migrate:fresh --seed >/dev/null 2>&1 && echo "  seeded." )

echo "▶ Starting Laravel API on :$API_PORT"
( cd "$ROOT/api" && php artisan serve --host=127.0.0.1 --port=$API_PORT ) &
API_PID=$!

echo "▶ Starting Vite web on :$WEB_PORT"
( cd "$ROOT/web" && npm run dev -- --port $WEB_PORT ) &
WEB_PID=$!

trap 'echo; echo "▶ Stopping…"; kill $API_PID $WEB_PID 2>/dev/null || true' INT TERM EXIT

echo
echo "  API  → http://localhost:$API_PORT/api/v1"
echo "  Web  → http://localhost:$WEB_PORT"
echo "  Demo → owner@biztrack.local / biztrack1  (also bplo@, sanitary@, fire@, admin@)"
echo
wait
