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
