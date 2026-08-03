#!/usr/bin/env bash
# Stop the tester demo. Leaves the worktree and the database alone.
set -uo pipefail
pkill -f 'cloudflared tunnel --url http://localhost:5180' && echo "tunnel stopped"
kill $(lsof -ti tcp:5180) 2>/dev/null && echo "preview server stopped"
kill $(lsof -ti tcp:8082) 2>/dev/null && echo "demo api stopped"
exit 0
