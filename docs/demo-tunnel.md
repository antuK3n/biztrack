# Running the tester demo

## Why it is set up this way

The tunnel used to point at the Vite dev server, which reads the working tree
and hot-reloads it. So every save while we were coding landed in front of
whoever was mid-application — testers watched fields move under them and said
so. That is the problem this layout exists to solve.

Testers now get a **built bundle from a pinned branch in a separate worktree**.
It changes when someone deliberately rebuilds, and not before. Our editing,
branch switching, rebasing and test runs cannot touch it.

```
main tree   ~/Documents/GitHub/biztrack          any working branch    ← we edit here
demo tree   ~/Documents/GitHub/biztrack-demo     branch: demo          ← testers see this

  :5180  vite preview   serves biztrack-demo/web/dist   (no HMR)
  :8082  php artisan serve  from biztrack-demo/api
  :8080  php artisan serve  from the main tree           ← our own work
  :5173  vite dev           from the main tree           ← our own work
  :8787  R / plumber        localhost only, never tunnelled

cloudflared --url http://localhost:5180
```

Both API processes point at the **same** `api/database/database.sqlite`, so
tester data is continuous and we can see what they filed. Only the *code* is
isolated, which is the part that was hurting them.

## Publishing an update to testers

Deliberate, and the only way their world changes:

```bash
cd ~/Documents/GitHub/biztrack-demo
git merge --ff-only <branch-you-want-live>   # or: git merge origin/main
cd web && npm run build
```

`vite preview` serves `dist/` off disk, so the rebuild is live the moment it
finishes. No restart needed.

If the API changed too, first check that the register has been migrated for it
(see [The register has to be migrated first](#the-register-has-to-be-migrated-first)).
If it has not, this refuses and prints what to run:

```bash
~/Documents/GitHub/biztrack/scripts/lib/demo-preflight.sh \
  ~/Documents/GitHub/biztrack-demo/api \
  ~/Documents/GitHub/biztrack/api/database/database.sqlite
```

Only once that prints "Preflight passed", restart the demo API:

```bash
kill $(lsof -ti tcp:8082); cd ~/Documents/GitHub/biztrack-demo/api
DB_DATABASE=~/Documents/GitHub/biztrack/api/database/database.sqlite \
  APP_DEBUG=false nohup php artisan serve --port=8082 &
```

## Starting from cold

```bash
scripts/demo-up.sh          # from the main tree
```

It prints the tunnel URL. Stop everything with `scripts/demo-down.sh`.

## The register has to be migrated first

`demo-up.sh` and `demo-deploy.sh` put new code in front of the live register,
and they never migrate it. That is deliberate (AGENTS.md §2.2): the register
holds real tester filings, and a migration on it is a person's decision, made
with a backup in hand.

That left one gap, and a tester found it before we did [checklist 2026-09-27,
Login 7]. The owner sign-in page answered "Something went wrong" to a correct
password. The likeliest cause, and the one we could reproduce, is this
branch's code running against a register one step behind:

```
SQLSTATE[HY000]: General error: 1 table audit_logs has no column named snapshot
(… insert into "audit_logs" ("user_id", "action", … "snapshot", …))
```

Every sign-in writes an audit row, and the audit row now has a `snapshot`
column that `2026_09_27_000120_keep_what_was_removed_in_the_audit_log` adds.
So every account, at every door, got a 500. The code and the database were
each fine; they were the wrong pair.

So both scripts now run `scripts/lib/demo-preflight.sh` before they touch
anything. If a migration is pending, they start nothing, list the pending
files, and print the procedure below with the real paths filled in. They
still do not run it for you. Two things the check deliberately does:

- It asks `php artisan migrate:status --pending`, which only reads. **Not**
  `migrate --pretend`: pretend still fires the migration events, and on SQLite
  `AppServiceProvider` answers those by dropping and re-creating the reporting
  views. A dry run that writes to the register is not a dry run.
- It also refuses if the demo's `api/vendor` is a link to another checkout's
  vendor. See [The worktree](#the-worktree) for why that matters.

### What this branch needs, in order

`ken/checklist-0927` (at `2c77033`) adds these seven, all dated 2026-09-27.
The list is `git diff --name-status c49395a 2c77033 -- api/database/migrations`,
where `c49395a` is the `dev` commit the checklist branch was started from.
Nothing was modified or removed, only added. The order is the order Laravel
runs them, which is by the full filename (three share the `000100` prefix):

1. `2026_09_27_000100_email_codes_for_sign_in_and_address_confirmation`: a new `email_codes` table. Sign-in uses it once a real mailer is set.
2. `2026_09_27_000100_every_office_reads_its_own_analytics`: permission rows.
3. `2026_09_27_000100_give_bplo_and_the_super_admin_permit_revoke`: permission rows. Revoke errors without it.
4. `2026_09_27_000100_let_the_register_hold_what_the_old_system_issued`: a `legacy_owners` table and `legacy_id` columns. It also makes `businesses.owner_user_id` and `permits.application_id` nullable, which SQLite does by **rebuilding both tables**, so run it when nobody is filing.
5. `2026_09_27_000110_record_each_import_of_the_old_register`: a `legacy_imports` table and one permission row.
6. `2026_09_27_000120_keep_what_was_removed_in_the_audit_log`: `audit_logs.snapshot`. **Sign-in 500s until this one runs.**
7. `2026_09_27_000130_give_reporting_tools_stable_views`: three read-only views.

If the register is also behind `dev` itself, the preflight lists those too.
Trust its list over this one.

### The procedure

From the demo worktree, after the new code is checked out there:

```bash
DB=~/Documents/GitHub/biztrack/api/database/database.sqlite
PRE=~/Documents/GitHub/biztrack/scripts/lib/demo-preflight.sh

# 1. Back up. The register is in WAL mode, so .backup, never cp: a copy of
#    the main file alone can miss writes that are still in the -wal file.
sqlite3 "$DB" ".backup '$(dirname "$DB")/backup-$(date +%Y%m%d-%H%M).sqlite'"

# 2. Row counts before. Read-only; one "table count" per line.
"$PRE" counts "$DB" > /tmp/biztrack-counts-before.txt

# 3. One migration at a time, in the order above.
cd ~/Documents/GitHub/biztrack-demo/api
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000100_email_codes_for_sign_in_and_address_confirmation.php --force
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000100_every_office_reads_its_own_analytics.php --force
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000100_give_bplo_and_the_super_admin_permit_revoke.php --force
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000100_let_the_register_hold_what_the_old_system_issued.php --force
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000110_record_each_import_of_the_old_register.php --force
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000120_keep_what_was_removed_in_the_audit_log.php --force
DB_DATABASE="$DB" php artisan migrate --path=database/migrations/2026_09_27_000130_give_reporting_tools_stable_views.php --force

# 4. Row counts after, and compare.
"$PRE" counts "$DB" > /tmp/biztrack-counts-after.txt
diff /tmp/biztrack-counts-before.txt /tmp/biztrack-counts-after.txt

# 5. The check the deploy scripts run. It should now pass.
"$PRE" ~/Documents/GitHub/biztrack-demo/api "$DB"
```

Reading the diff: new tables (`email_codes`, `legacy_owners`,
`legacy_imports`), new rows in `permissions` / `role_permissions`, and seven
more rows in `migrations` are expected. **Any existing table with fewer rows
is not.** Stop there, and restore the backup before anyone files again.

Stop at the first migration that fails. Do not skip it and carry on.

Rehearsed on a copy of the seeded register at the pre-branch schema, put
into WAL mode: steps 2 to 5 ran clean, the diff showed only the three new
tables and `migrations` 89 → 96, and owner sign-in went from 500 to 200.
Step 1 was not rehearsed, because the machine it ran on had no `sqlite3`.

## Before you hand out the URL

- `APP_DEBUG` must be **false** — a stack trace on a public URL leaks paths,
  environment and query fragments.
- Plumber (:8787) must stay bound to `127.0.0.1`. It has no authentication of
  its own, so anything that can reach it can read the register.
- Only :5180 is tunnelled. Never tunnel the API or the R service directly.
- Demo accounts all share one password. That is acceptable only because the
  data is seeded; never reuse those credentials anywhere real.

## Known gap

`FRONTEND_URL` defaults to `http://localhost:5173`, and the permit certificate
prints a verify link built from it. While the tunnel is live, every permit a
tester downloads tells them to verify at an address only we can reach. Set
`FRONTEND_URL` in `api/.env` to the current tunnel URL before a session that
involves printing permits.

Quick tunnels also get a new random hostname every restart, so that value has
to be updated each time. A named tunnel would fix both.

## The worktree

Created once with:

```bash
git worktree add ../biztrack-demo demo
```

`node_modules` was symlinked to the main tree's, and `.env` files were copied
in. None of those are tracked, so a fresh worktree has neither.

**`api/vendor` must be a copy, not a symlink.** It used to be a symlink too,
and that quietly broke the pinning this whole page is about. Composer's
autoloader loads `App\…` classes from beside the vendor folder it lives in,
and PHP follows the symlink to find that folder. So with a linked vendor, the
demo API ran the **main tree's** controllers, models and `App\Support`
classes, from whatever branch was checked out there, under the demo's routes
and migrations. Checked by asking PHP, through a linked vendor, which file
`App\Support\Audit` came from: it named the other checkout's file.
(`node_modules` does not have this problem: Vite bundles the demo's own
`src/`, and the libraries are the same either way.)

The deploy scripts now refuse to start while this is true, and print the fix:

```bash
cd ~/Documents/GitHub/biztrack-demo/api
rm vendor                                        # the link only
cp -R ~/Documents/GitHub/biztrack/api/vendor vendor
composer dump-autoload
```

After that, `composer install` in the demo worktree only touches the demo.
`git worktree list` shows it; `git worktree remove ../biztrack-demo` undoes it.

Note that a branch checked out in a worktree **cannot** be checked out in the
main tree at the same time. That is the safety property, not an obstacle: it is
what stops someone accidentally committing to `demo` from the tree they are
developing in.
