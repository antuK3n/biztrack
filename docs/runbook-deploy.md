# BizTrack — Deployment Runbook (MISD box / defense laptop)

Target: one machine with Docker + Docker Compose. Everything runs offline after
the images are built (defense-day fallback per master plan §4).

## 0. Prereqs
- Docker Engine + Compose v2
- The repo cloned at `/opt/biztrack` (or anywhere)
- `web/dist` built: `cd web && npm ci && npm run build`

## 1. Configure
```bash
cp api/.env.example api/.env.production
# then edit api/.env.production:
#   APP_ENV=production  APP_DEBUG=false  APP_KEY=(php artisan key:generate --show)
#   APP_URL=http://<host>          FRONTEND_URL=http://<host>
#   DB_CONNECTION=pgsql DB_HOST=db DB_PORT=5432 DB_DATABASE=biztrack
#   DB_USERNAME=biztrack DB_PASSWORD=<strong>
#   QUEUE_CONNECTION=database  MAIL_MAILER=log  SMS_DRIVER=log
#   PAYMENT_DRIVER=simulated   DEMO_PASSWORD=<demo pw>
```
Web API base is baked at build time: `web/.env.production` → `VITE_API_URL=http://<host>/api/v1`,
rebuild `web/dist` if the host changes.

## 2. Launch
```bash
docker compose -f infra/docker-compose.prod.yml up -d --build
docker compose -f infra/docker-compose.prod.yml exec app php artisan migrate --seed --force
```
Open `http://<host>`. Demo accounts per seeder (password = DEMO_PASSWORD).

## 3. Daily ops
- Scheduler container runs `schedule:work` (expiry scans etc. — see `biztrack:scan-permits`).
- Queue container processes the database queue.
- Logs: `docker compose -f infra/docker-compose.prod.yml logs -f app queue scheduler`.
- Backups: `php artisan backup:run` inside `app` (see docs/backups if configured);
  DB dump fallback: `docker compose exec db pg_dump -U biztrack biztrack > backup.sql`.

## 4. Restore drill (R32)
```bash
docker compose -f infra/docker-compose.prod.yml exec -T db psql -U biztrack -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public; CREATE EXTENSION postgis;'
cat backup.sql | docker compose -f infra/docker-compose.prod.yml exec -T db psql -U biztrack biztrack
```
Verify: log in, open an application, scan a permit QR against /verify.

## 5. Dev backing services (optional)
`docker compose -f infra/docker-compose.yml up -d` → PostGIS on :5432, Mailpit UI on :8025.
Dev currently uses SQLite by default (documented delta); to use Postgres locally set the
DB_* vars in `api/.env` (requires the `pdo_pgsql` PHP extension).

## 6. Jitsi (deferred)
Meeting rooms (plan S5) require the docker-jitsi-meet quartet + public host + JWT secrets.
Not provisioned in this build; the officer-request "meeting" type is out of scope until then.

## 7. Known limits
- Expo push needs EAS credentials; in-app notification center is the offline floor.
- TLS: put the host behind the LGU reverse proxy or add certbot to nginx for a real domain.

## PHP upload limits

`DocumentController` validates `max:10240` — 10 MB — and the applicant is told
that. PHP's own defaults are lower (`upload_max_filesize=2M`,
`post_max_size=8M`), and PHP rejects an oversized upload *before* Laravel runs,
so the applicant gets a raw 413 rather than a validation message.

The gap is the whole 2–10 MB range, which is exactly where a phone photo of a
barangay clearance or a scanned lease falls. It presents as "the upload failed"
with no reason.

Set these wherever PHP runs in production (php.ini, the FPM pool, or the
container image):

    upload_max_filesize = 12M
    post_max_size       = 13M

Slightly above 10 MB on purpose: `post_max_size` has to cover the whole request
body, not just the file, and a multipart POST carries the other fields too.

For local development `php artisan serve` reads the ini, not `-d` flags passed to
the parent process, so the dev server is started with:

    PHP_INI_SCAN_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;'):$(pwd)/.dev-php" \
      php artisan serve --port=8080

`api/.dev-php/uploads.ini` holds the two settings. Scanned in addition to the
normal config, so no global php.ini is modified.
