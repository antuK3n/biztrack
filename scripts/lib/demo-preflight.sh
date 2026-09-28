#!/usr/bin/env bash
#
# Checks that must pass before a tester stack is started. Sourced by
# demo-up.sh and demo-deploy.sh; also runnable on its own:
#
#   scripts/lib/demo-preflight.sh <demo api dir> <database>   # the checks
#   scripts/lib/demo-preflight.sh counts <database>            # row counts
#
# Nothing here writes to the database. Every check reads, and a failed check
# refuses and prints the steps for a person to take.
#
# ── Why this exists [checklist 2026-09-27, Login 7] ──────────────────────────
#
# An owner signing in on the tester tunnel got "Something went wrong" instead
# of their dashboard. The cause we could reproduce, and the likeliest one: a
# 500 from this branch's code against a register that had not had this
# branch's migrations run:
#
#   SQLSTATE[HY000]: General error: 1 table audit_logs has no column named
#   snapshot … insert into "audit_logs" (… "snapshot" …)
#
# Every sign-in writes an audit row (`Audit::log('user.logged_in', …)`), and
# `Audit::log` now always writes the `snapshot` column that
# 2026_09_27_000120_keep_what_was_removed_in_the_audit_log adds. So the
# password was right, the session was never issued, and every account on
# every door got the same 500.
#
# Nothing was wrong with the code, and nothing was wrong with the database on
# its own. They were the wrong pair. The deploy scripts build new code and
# point it at the live register, and they never migrate (on purpose: the
# register is real tester data, AGENTS.md §2.2). That left the gap between
# "the code was deployed" and "the schema was brought up to it" to memory,
# and the first person to notice was a tester.
#
# So the scripts now refuse to start while that gap is open, and say how to
# close it. They still do not close it themselves. A migration that rebuilds
# a table on the only copy of the register is a step a person takes with a
# backup in hand, not a side effect of redeploying.
#
# The login code is left as it is. Catching the failed audit write so that
# sign-in "works" would hide exactly this: an audit trail that silently stops
# recording is worse than a sign-in that loudly fails.
#
# ── Two checks, because the first alone could pass on the wrong code ─────────
#
# 1. Is the PHP code that will run the demo worktree's own?
#
#    The demo worktree's `api/vendor` has been a symlink to the main tree's
#    vendor. Composer's autoloader finds `App\…` classes relative to its own
#    file, and PHP resolves `__DIR__` through symlinks, so with a linked vendor
#    every controller, model and `App\Support` class is loaded from the MAIN
#    tree, whatever branch is checked out there. Only the routes, config and
#    migrations come from the demo. (Verified: with a linked vendor,
#    `ReflectionClass(App\Support\Audit::class)->getFileName()` names the
#    other tree's file.)
#
#    That quietly undoes the pinning docs/demo-tunnel.md describes, and it
#    would blind check 2: the demo's migration list could be complete while
#    the main tree's code needs columns the demo branch has never heard of.
#    So the first question is where `App\Models\User` actually loads from.
#
# 2. Has every migration in the demo's code been run on the register?
#
#    `php artisan migrate:status --pending=3`, which only reads the
#    `migrations` table and the migrations folder. Exit 3 means something is
#    pending, 0 means nothing is; any other exit (no database file, no
#    migrations table, a broken .env) is an answer we cannot read, and the
#    check refuses rather than guess.
#
#    NOT `migrate --pretend`. Pretend still fires the MigrationsStarted and
#    MigrationsEnded events, and AppServiceProvider answers those on SQLite by
#    dropping and re-creating the reporting views (App\Support\ReportViews).
#    A dry run that writes to the live register is not a dry run.

# Row counts for every table, one "name count" per line, sorted. Opened
# read-only, so it cannot change the file it is measuring. PHP rather than
# the sqlite3 CLI because PHP is certain to be on any machine that runs the
# API, and the counts are needed on both sides of every migration.
demo_row_counts() {
  local db="$1"
  php -r '
    $pdo = new PDO("sqlite:".$argv[1], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $tables = $pdo->query("select name from sqlite_master where type = \"table\" and name not like \"sqlite_%\" order by name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        printf("%s %d\n", $t, $pdo->query("select count(*) from \"".str_replace("\"", "\"\"", $t)."\"")->fetchColumn());
    }
  ' "$db"
}

# Check 1. Prints the reason and returns 1 when the code is borrowed.
demo_code_is_its_own() {
  local api="$1" real loaded vendor
  [ -d "$api" ] || { echo "REFUSING: no such directory: $api" >&2; return 1; }
  api="$(cd "$api" && pwd)"
  real="$(cd "$api" && pwd -P)"
  loaded="$(cd "$api" && php -r '
    require "vendor/autoload.php";
    echo (new ReflectionClass(App\Models\User::class))->getFileName();
  ' 2>&1)" || {
    echo "REFUSING: could not load the demo's PHP code to see where it comes from:" >&2
    echo "$loaded" | sed 's/^/    /' >&2
    return 1
  }

  case "$loaded" in
    "$real"/app/*) return 0 ;;
  esac

  vendor="$(cd "$api/vendor" && pwd -P)"
  cat >&2 <<EOF
REFUSING: the demo would run another checkout's PHP code.

    serving from   $real
    vendor is      $vendor
    App\\ loads     $loaded

Composer loads every App\\ class from beside the vendor folder it lives in,
so the testers would get that checkout's controllers and models under the
demo's routes and migrations. That is not the pinned code, and the migration
check could not vouch for it.

EOF
  if [ -L "$api/vendor" ]; then
    cat >&2 <<EOF
Give the demo a vendor of its own. This copies; the other checkout's vendor
is not touched:

    rm "$api/vendor"            # a symlink, so this removes the link only
    cp -R "$vendor" "$api/vendor"
    (cd "$api" && composer dump-autoload)

EOF
  else
    echo "Regenerate the demo's autoloader: (cd \"$api\" && composer dump-autoload)" >&2
    echo >&2
  fi
  echo "Then run this script again." >&2
  return 1
}

# Check 2. Prints the pending files and the procedure, and returns 1, when the
# register is behind the code.
demo_register_is_migrated() {
  local api="$1" db="$2" out rc pending self stamp
  # `…/biztrack/../biztrack-demo/api` → `…/biztrack-demo/api`, so the commands
  # printed below read as a place, not a sum.
  api="$(cd "$api" 2>/dev/null && pwd)" || { echo "REFUSING: no such directory: $1" >&2; return 1; }
  self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"

  out="$(cd "$api" && DB_DATABASE="$db" php artisan migrate:status --pending=3 --no-ansi 2>&1)" && rc=0 || rc=$?

  if [ "$rc" -eq 0 ]; then
    return 0
  fi
  if [ "$rc" -ne 3 ]; then
    echo "REFUSING: could not read which migrations $db has had (exit $rc):" >&2
    echo "$out" | sed 's/^/    /' >&2
    return 1
  fi

  # One migration name per line, in the order Laravel will run them. The name
  # is the first field and "Pending" the last; the dots between them change
  # with the terminal width, so nothing else is relied on.
  pending="$(printf '%s\n' "$out" | awk '$NF == "Pending" { print $1 }')"
  stamp="$(date +%Y%m%d-%H%M)"

  {
    echo "REFUSING: the register is missing $(printf '%s\n' "$pending" | grep -c .) migration(s) the demo's code expects."
    echo
    echo "    database   $db"
    echo "    code       $api"
    echo
    printf '%s\n' "$pending" | sed 's/^/    /'
    echo
    echo "Served like this, the first query that touches a missing column or"
    echo "table answers 500. Sign-in is one: every sign-in writes an audit row."
    echo "Nothing has been started, stopped or changed."
    echo
    echo "This script does not migrate the live register (AGENTS.md §2.2). By hand,"
    echo "one file at a time, at a quiet moment (a migration that rebuilds a table"
    echo "can make a tester's save wait or fail while it runs):"
    echo
    echo "  1. Back it up. The register is in WAL mode, so use .backup, not cp:"
    echo
    echo "       sqlite3 \"$db\" \".backup '$(dirname "$db")/backup-$stamp.sqlite'\""
    echo
    echo "  2. Count the rows first:"
    echo
    echo "       \"$self\" counts \"$db\" > /tmp/biztrack-counts-before.txt"
    echo
    echo "  3. Run each migration on its own, in this order:"
    echo
    echo "       cd \"$api\""
    printf '%s\n' "$pending" | while read -r m; do
      echo "       DB_DATABASE=\"$db\" php artisan migrate --path=database/migrations/$m.php --force"
    done
    echo
    echo "  4. Count again and compare. New tables and new permission rows are"
    echo "     expected; any existing table with FEWER rows is not. Stop there and"
    echo "     restore the backup if you see one:"
    echo
    echo "       \"$self\" counts \"$db\" > /tmp/biztrack-counts-after.txt"
    echo "       diff /tmp/biztrack-counts-before.txt /tmp/biztrack-counts-after.txt"
    echo
    echo "Then run this script again."
  } >&2
  return 1
}

# Both checks, both reported: a person fixing one should not rediscover the
# other on the next run.
demo_preflight() {
  local api="$1" db="$2" ok=0
  demo_code_is_its_own "$api" || ok=1
  demo_register_is_migrated "$api" "$db" || ok=1
  return $ok
}

# Run directly rather than sourced.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  set -euo pipefail
  case "${1:-}" in
    counts)
      [ $# -eq 2 ] || { echo "usage: $0 counts <database>" >&2; exit 2; }
      demo_row_counts "$2"
      ;;
    ''|-h|--help)
      echo "usage: $0 <demo api dir> <database>   |   $0 counts <database>" >&2
      exit 2
      ;;
    *)
      [ $# -eq 2 ] || { echo "usage: $0 <demo api dir> <database>" >&2; exit 2; }
      demo_preflight "$1" "$2" && echo "Preflight passed: $1 is its own code, and $2 has every migration it expects."
      ;;
  esac
fi
