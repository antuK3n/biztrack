# BizTrack

Business-permit processing for the City of Malabon. One application covers up
to six permits (business, sanitary, fire safety, occupancy, environmental,
market clearance). Six LGU offices review in parallel. The applicant pays a
simulated fee, tracks every step, messages the reviewing office, and ends up
with a QR-coded permit anyone can verify without logging in.

A PUP BSIT capstone by Group 12: Angeles, Aro, Makiling, Mondragon.

## Layout

```
biztrack/
├── api/     Laravel 13 REST API (Sanctum, RBAC, SQLite in dev)
├── web/     React 18 + TypeScript + Vite + Tailwind SPA
├── mobile/  Expo + React Native owner app
├── r/       R analytics prototype (SPC control charts + DES staffing simulation)
├── infra/   Docker compose for dev services and production
└── docs/    API contract, DB solidification, traceability, deploy runbook, Bruno
```

## Run it

Prereqs: PHP 8.4, Composer, Node 20+.

```bash
cd api && composer install && php artisan key:generate && cd ..
cd web && npm install && cd ..
./dev.sh
```

Web on http://localhost:5173, API on http://localhost:8080/api/v1.

Demo accounts, password `biztrack1`: `owner@biztrack.local` (applicant),
`bplo@`, `sanitary@`, `fire@`, `obo@`, `cenro@`, `market@` (office queues),
`admin@biztrack.local` (super admin).

Verify a permit without logging in: http://localhost:5173/verify/{permit_number}.

## Mobile

```bash
cd mobile && npm i && npx expo start
```

Scan the QR in Expo Go. On a physical device, point the app at your machine:
`EXPO_PUBLIC_API_URL=http://<your-lan-ip>:8080/api/v1 npx expo start`. The app
signs in, tracks applications, pays the simulated fee, resubmits returned
filings, and shows issued permits. Filing new applications stays on the web.

## Tests and quality

```bash
cd api && php artisan test    # Pest: workflow lifecycle, auth, authorization
```

`docs/bruno/` documents every endpoint as a runnable collection. Nightly
backups run through spatie/laravel-backup; the restore drill is in
`docs/runbook-deploy.md`.

## Deploy

```bash
docker compose -f infra/docker-compose.prod.yml up -d --build
docker compose -f infra/docker-compose.prod.yml exec app php artisan migrate --seed --force
```

Full steps, env vars, and the restore drill: `docs/runbook-deploy.md`.

## Honest scope notes

Fees are computed from the New Revenue Code of Malabon 2016 (Ord. A10-2016) via
~420 seeded rules with per-line citations; the extraction, verification trail,
and every source-print defect are in `docs/revenue-code-extract.md`. Amounts the
ordinance leaves discretionary or unprinted (PIL, market stall rates) surface as
officer-assessed lines rather than invented numbers.

Payments, SMS, and email use simulated drivers by design; each one swaps for a
real provider behind an interface with no schema change. Zoning validation is
excluded until the city releases official zone polygons (the CPDO department is
seeded, the four zoning tables are documented in `docs/schema-deltas.md`). The
chatbot tables exist and stay dormant. `docs/traceability.md` maps every paper
requirement to its implementation status.
