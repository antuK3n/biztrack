# PostgreSQL: testing on it, and what production must set

Production is **Azure Database for PostgreSQL 16 (Flexible Server)**. Dev and
demo run on SQLite, and SQLite accepts queries PostgreSQL refuses, so the Pest
suite also runs against PostgreSQL 16. Run it before merging anything that
touches a query, a migration or a seeder.

---

## 1. Running the suite on PostgreSQL locally

Docker is not needed. A throwaway server in `/tmp` leaves nothing behind:

```bash
brew install postgresql@16
PG=/opt/homebrew/opt/postgresql@16/bin

$PG/initdb -D /tmp/biztrack-pg16 -U postgres --auth=trust -E UTF8 --locale=en_US.UTF-8
$PG/pg_ctl -D /tmp/biztrack-pg16 -l /tmp/biztrack-pg16/server.log \
  -o "-p 5433 -k /tmp -c listen_addresses=127.0.0.1 -c fsync=off" start
$PG/createdb -h 127.0.0.1 -p 5433 -U postgres biztrack_test

cd api && php artisan test --compact --configuration=phpunit.pgsql.xml

$PG/pg_ctl -D /tmp/biztrack-pg16 stop && rm -rf /tmp/biztrack-pg16
```

`phpunit.pgsql.xml` is `phpunit.xml` with the connection swapped. It forces
`DB_CONNECTION=pgsql` and defaults to `127.0.0.1:5433`, database
`biztrack_test`, user `postgres`, no password. Set any of `DB_HOST`,
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME` or `DB_PASSWORD` in the shell to point
it somewhere else. `RefreshDatabase` wipes that database, so never point it at
one that holds anything.

`phpunit.xml` is unchanged: `php artisan test` still runs on SQLite in memory.
PHP needs the `pdo_pgsql` extension (Homebrew's PHP has it).

The PostgreSQL run is slower (about 8 minutes against about 2).
`AnalyticsHistorySeederTest` takes most of it: it writes thousands of rows per
case over a real connection.

---

## 2. What production must set (`api/.env`)

| Key | Value |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `<server>.postgres.database.azure.com` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | the database, e.g. `biztrack` |
| `DB_USERNAME` / `DB_PASSWORD` | the application's login. Flexible Server takes the plain user name, with no `@server` suffix. |
| `DB_SSLMODE` | **`require`**. Azure refuses unencrypted connections, and the default `prefer` only works because the server insists. Use `verify-full` to also check the server certificate. |
| `DB_SSLROOTCERT` | only with `verify-full`: the path to the root CA bundle Azure publishes for Flexible Server (Azure's "TLS/SSL" page for it). |
| `DB_REPORT_ROLE` | only if the ODBC reporting role was created under a name other than `biztrack_report` (see §4). |

No PostgreSQL extension is needed; nothing uses PostGIS.

---

## 3. First deploy, and every deploy after

```bash
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
```

Both are safe to run again, and are meant to be. A second `migrate` has
nothing to run. `ProductionSeeder` writes only reference data (departments,
barangays, PSIC codes, permit types, requirement lists, zoning, roles and
permissions, fee rules) and changes nothing on a second run. It leaves alone
anything an admin has since edited, such as a form signatory's name.

**Never `migrate --seed` or `db:seed` on its own in production.** Both run
`DatabaseSeeder`, which ends in `DemoSeeder`: demo accounts that share one
password, and filings that never happened. The analytics history seeders are
demo data too.

### The first super admin

`ProductionSeeder` creates no account, so no password is ever stored in the
repository. On the server:

```bash
php artisan tinker --execute '
$u = App\Models\User::create([
  "name" => "First Last", "first_name" => "First", "last_name" => "Last",
  "email" => "admin@malabon.gov.ph", "password" => Str::password(40),
  "is_active" => true, "email_verified_at" => now(), "data_privacy_consent_at" => now(),
]);
$u->roles()->sync(App\Models\Role::where("name", "admin")->pluck("id"));'
```

Nobody ever sees that password. The admin sets their own through **Forgot
password** on the admin sign-in page, which needs mail configured first
(`docs/email-setup.md`).

---

## 4. Things that behave differently on PostgreSQL, and are handled

Each one below broke on PostgreSQL once. The PostgreSQL run is what catches a
new one.

- **Report views.** PostgreSQL will not alter or drop a column that a view
  reads. `App\Support\ReportViews` drops the three `report_…` views while
  `migrate` runs and re-creates them after. Re-creating a view loses its grants,
  so they are given back afterwards, and so is `DB_REPORT_ROLE`'s SELECT
  (`docs/odbc.md`). On Azure there is no superuser: run the
  `biztrack:report-role-sql` script as the server admin. That step is untested
  on Azure itself.
- **Length limits are real.** `string()` is `varchar(255)`. SQLite ignores the
  limit and PostgreSQL rejects the whole write. Anything a validator lets past
  255 characters goes in a `text` column.
- **`LIKE` is case-sensitive.** Search with `whereLike()`, which is `ILIKE` on
  PostgreSQL. A bare `where(col, 'like', …)` is only right for an exact-case
  prefix match on a code.
- **`NULL` sorts last ascending**, which is the opposite of SQLite. Any sort on
  a nullable column says `nulls first` or `nulls last`.
- **`ORDER BY` cannot use a select alias inside an expression.**
  `COALESCE(alias, …)` and `CASE WHEN alias …` fail. Sort on a subquery or an
  EXISTS instead. A bare `orderBy('alias')` is fine.
- **Date functions differ.** `strftime` is SQLite only. Prefer Laravel's
  `whereDate`/`whereYear`, or branch on `DB::getDriverName()` (see
  `AnalyticsController::yearMonth`).
- **A failed statement aborts its transaction.** Catching a unique violation
  and then querying again only works outside a transaction. Inside one, use
  `createOrFirst`, which wraps the insert in a savepoint.
- **An unknown quoted column is an error.** SQLite treats `"subject"` as the
  string `'subject'` when there is no such column, so a query naming a wrong
  column can pass there and assert nothing.
