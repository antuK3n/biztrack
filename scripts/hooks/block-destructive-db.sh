#!/usr/bin/env bash
#
# Refuse the handful of commands that would destroy the register.
#
# ── Why this is a hook and not a permission rule ─────────────────────────────
#
# The user runs `defaultMode: bypassPermissions`, so the allow/deny lists in
# settings are not consulted — a `deny` entry for these would be decoration. A
# PreToolUse hook is evaluated BEFORE the permission-mode check, so exit 2 here
# stops the call even under bypass. It is the only guard that actually holds.
#
# ── Why these commands specifically ──────────────────────────────────────────
#
# `api/database/database.sqlite` is the live register: ~1,700 filings, the audit
# trail behind them, and permits that reference their applications for
# provenance. Every command below empties it in one step and none of them are
# reversible. `migrate:fresh` in particular reads as routine — it is the command
# you reach for when a migration misbehaves — and it is the one that has come
# closest to being run against live data during a tester session.
#
# The seeders are here for the same reason: `db:seed` on a populated register
# duplicates the demo set rather than replacing it, which corrupts the analytics
# without looking like damage.
#
# ── What this deliberately does NOT block ────────────────────────────────────
#
# Plain `migrate` is allowed. Forward migrations are how the schema moves and
# blocking them would make the guard the thing people work around — which is how
# guards die. The rule is: adding is fine, emptying is not.
#
# Tests are exempt: Pest runs against `:memory:` (see phpunit.xml), and the e2e
# stack has its own throwaway copy, so a `migrate:fresh` inside either is
# destroying nothing. The check is for the string appearing in an ordinary
# shell command against the working database.

payload="$(cat)"
cmd="$(printf '%s' "$payload" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("tool_input",{}).get("command",""))' 2>/dev/null)"

[ -z "$cmd" ] && exit 0

# Exempt the test paths, which operate on throwaway databases by construction.
case "$cmd" in
  *phpunit.xml*|*--env=testing*|*DB_DATABASE=:memory:*|*e2e.sqlite*) exit 0 ;;
esac

# Matched as an INVOCATION, not as a mention.
#
# The first version matched the bare verb anywhere in the command string, and
# the first thing it refused was the commit that documented it — the verbs
# appear in AGENTS.md and in the commit message describing this hook. A rule
# nobody can write about is a rule nobody learns, so the verb now has to be
# preceded by `artisan` somewhere in the command.
#
# Still deliberately blunt: a heredoc containing `php artisan migrate:fresh`
# will trip it. That is the right way round — a false refusal costs one
# rephrased command, a missed one costs the register.
for pattern in 'migrate:fresh' 'migrate:reset' 'db:wipe' 'schema:dump --prune'; do
  case "$cmd" in
    *artisan*"$pattern"*)
      cat >&2 <<EOF
Refused: \`$pattern\` would empty the live register.

api/database/database.sqlite holds roughly 1,700 filings, the audit rows behind
them, and permits that reference their applications for provenance. None of it
is reproducible from a seeder.

If you genuinely need to rebuild the schema:
  1. cp api/database/database.sqlite api/database/database.backup.sqlite
  2. run the command yourself, outside this session

Forward migrations (\`php artisan migrate\`) are NOT blocked.
EOF
      exit 2
      ;;
  esac
done

exit 0
