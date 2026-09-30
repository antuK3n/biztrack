# ODBC — reporting out, importing in

BizTrack speaks ODBC in both directions:

- **Out:** Excel, Power BI or any ODBC tool reads three read-only **report
  views**, through a PostgreSQL login that can see those views and nothing else.
- **In:** the super admin imports the city's old register straight from an ODBC
  data source, through the same dry run and validation as a CSV import.

This guide is written for MISD. Production is PostgreSQL 16; the demo runs on
SQLite (see the note at the end of each half).

---

## Part 1 — Reporting out (Excel, Power BI)

### What the tools can read

| View | One row per | Columns |
|---|---|---|
| `report_businesses` | business on the register | `business_id`, `account_no`, `business_name`, `trade_name`, `organization_type`, `registration_number`, `status`, `barangay`, `owner_name`, `owner_has_account` (0/1), `from_old_register` (0/1), `old_register_id`, `registered_at`, `status_changed_at` |
| `report_permits` | permit | `permit_id`, `permit_number`, `permit_type_code`, `permit_type`, `issuing_office`, `status`, `valid_from`, `valid_until`, `issued_at`, `renews_permit_number`, `tracking_id`, `application_type`, `business_id`, `account_no`, `business_name`, `barangay`, `from_old_register`, `old_register_id` |
| `report_payments` | payment | `payment_id`, `reference_number`, `amount`, `method`, `status`, `paid_at`, `tracking_id`, `application_type`, `payment_mode`, `business_id`, `account_no`, `business_name`, `barangay` |

The views are the stable contract. Tables are renamed and split as BizTrack
changes; the views keep these names. Build reports on the views, never on
tables.

**Left out on purpose (RA 10173):** owners' emails, mobile numbers, TINs,
street addresses beyond the barangay, and uploaded documents. If a report truly
needs one, it is added to a view deliberately — the reporting login is never
granted a table.

Soft-deleted businesses (and their permits and payments) are not in the views,
the same as on every screen.

### Step 1 — create the read-only login (on the database server)

BizTrack prints the SQL; it never runs it and never sees the password:

```bash
cd /var/www/api
php artisan biztrack:report-role-sql > /tmp/report-role.sql      # default role: biztrack_report
psql -U postgres -d biztrack -v report_password='<choose one>' -f /tmp/report-role.sql
rm /tmp/report-role.sql
```

The role can `CONNECT`, has `SELECT` on the three views only, and every session
it opens is read-only (`default_transaction_read_only = on`). A view reads its
tables with its owner's rights, which is why no table grant is needed. The
script is safe to re-run (it resets the password and grants).

Allow the reporting PCs in `pg_hba.conf`, for example:

```
host  biztrack  biztrack_report  10.0.0.0/8  scram-sha-256
```

Keep port 5432 inside the city network (questions-for-malabon.md B30).

### Step 2 — install psqlODBC on each reporting PC

- **Windows:** download the **psqlODBC** MSI (x64) from
  <https://www.postgresql.org/ftp/odbc/releases/> and install it. Match the
  bitness of Excel/Power BI — 64-bit Office needs the x64 driver.
- **macOS:** `brew install unixodbc psqlodbc`
- **Linux:** `sudo apt install odbc-postgresql unixodbc`

### Step 3 — make a DSN

**Windows:** *ODBC Data Sources (64-bit)* → *System DSN* → *Add* →
**PostgreSQL Unicode(x64)**:

| Field | Value |
|---|---|
| Data Source | `BizTrack Reports` |
| Database | `biztrack` |
| Server | the database server's address |
| Port | `5432` |
| User Name | `biztrack_report` |
| SSL Mode | `require` if the server has a certificate, else `prefer` |

*Test* should say "Connection successful".

**macOS / Linux** (`~/.odbc.ini` or `/etc/odbc.ini`):

```ini
[BizTrackReports]
Driver     = PostgreSQL Unicode
Servername = db.example.local
Port       = 5432
Database   = biztrack
Username   = biztrack_report
SSLMode    = prefer
```

### Step 4 — connect the tool

- **Excel:** *Data* → *Get Data* → *From Other Sources* → *From ODBC* → pick
  `BizTrack Reports` → sign in as `biztrack_report` → choose a `report_…` view.
- **Power BI Desktop:** *Get data* → *ODBC* → pick the DSN → *Database*
  credentials → choose the views. Refresh pulls current data.

### Demo / development (SQLite)

The same three views exist in the SQLite database. To read it from Excel on
the demo machine, install the **SQLite ODBC driver** (Christian Werner's,
<http://www.ch-werner.de/sqliteodbc/>; on macOS `brew install sqliteodbc`), make
a DSN whose *Database Name* is the path to a **copy** of
`api/database/database.sqlite`, and query the `report_…` views. SQLite has no
logins, so there is no read-only role — always point the tool at a copy, never
at the live file (AGENTS.md §2.2).

For developers: on both engines the views are dropped while `artisan migrate`
runs and re-created when it ends, so a later `->change()` on a table they read
does not fail (`App\Support\ReportViews`). PostgreSQL refuses to alter or drop
a column a view reads, just as SQLite refuses to rebuild the table under one.
On PostgreSQL the re-created views get their grants back: whoever held one
when the run started, plus the role named by `DB_REPORT_ROLE` (default
`biztrack_report`) whenever it exists. If the role was created under another
name, set `DB_REPORT_ROLE` to it, or the next deploy that migrates will lock
the reporting tools out.

---

## Part 2 — Importing in (from the old register)

The super admin's **Import** screen (and `php artisan biztrack:import-legacy`)
accepts a CSV in BizTrack's template, **or** an ODBC source. Both run the same
dry run first: counts of rows that will create, update or be rejected, and a
reason for every rejected row. Nothing is written until the import is
confirmed.

### What MISD installs on the BizTrack server

`pdo_odbc` is not part of the production image yet. Until it is, the screen
says so in plain words and the CSV route still works. In `infra/php/Dockerfile`:

```dockerfile
RUN apk add --no-cache unixodbc unixodbc-dev \
    && docker-php-ext-configure pdo_odbc --with-pdo-odbc=unixODBC,/usr \
    && docker-php-ext-install pdo_odbc
```

(Debian/Ubuntu without Docker: `sudo apt install unixodbc php8.4-odbc`.)

Then add the ODBC **driver for the old system's database** (for example
Microsoft's `msodbcsql18` for SQL Server, `odbc-postgresql` for PostgreSQL,
the MySQL Connector/ODBC for MySQL) and a DSN in `/etc/odbc.ini`:

```ini
[OLDBPLS]
Driver   = ODBC Driver 18 for SQL Server
Server   = old-bpls.example.local
Database = BPLS
Encrypt  = yes
```

Check it with `isql -v OLDBPLS <user> <password>`.

### Credentials

The login for the old database goes in the server's environment, never in the
import screen:

```
LEGACY_ODBC_USERNAME=bpls_readonly
LEGACY_ODBC_PASSWORD=…
```

(or embed them in the DSN). Give BizTrack a **read-only** account on the old
system. BizTrack also refuses anything but a single `SELECT`, but that is a
second lock, not the first.

### Mapping the old columns

On the screen, give the DSN name and either a table whose columns already use
the template's names, or a `SELECT` that renames them with `AS`:

```sql
SELECT biz_no      AS legacy_business_id,
       biz_name    AS business_name,
       own_fname   AS owner_first_name,
       own_lname   AS owner_last_name,
       street      AS address_line,
       brgy        AS barangay,
       permit_id   AS legacy_permit_id,
       permit_kind AS permit_type,
       permit_no   AS permit_number,
       date_issued AS valid_from,
       date_expiry AS valid_until
FROM bpls_master
```

The screen lists every template column and what goes in it; the same list is
the header row of the downloadable CSV template. Timestamps are fine — the time
is dropped. A slashed date is read **month first**; ask for `YYYY-MM-DD`
(questions-for-malabon.md B28).

### From the command line

```bash
php artisan biztrack:import-legacy --odbc=OLDBPLS --query="SELECT … FROM bpls_master" --dry-run
php artisan biztrack:import-legacy --odbc=OLDBPLS --table=bizexport            # asks before importing
php artisan biztrack:import-legacy old-register.csv --dry-run
```

### Re-running

Every row is keyed on the old system's own ids (`legacy_business_id`,
`legacy_permit_id`). Running the same source again updates what the first run
made rather than adding copies, so a failed or partial run is finished by
running it again. Large imports (over 1,000 rows) run on the queue worker —
production's `queue` container already runs one.

### Owners

An owner with no BizTrack account is kept as an **unclaimed** record; no
account is made and no password is set. They claim their businesses at sign-up
by entering a business account or permit number from before BizTrack, and
their surname must match the old register's (questions-for-malabon.md B29). An
owner whose email already belongs to a BizTrack business-owner account is
linked straight away.
