# BizTrack API

Laravel backend for BizTrack. Serves `/api/v1` with Sanctum bearer auth and
hand-rolled RBAC. SQLite in dev, PostgreSQL 16 + PostGIS in the prod compose.

## Run

```bash
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --port=8080
```

## Prove it

```bash
php artisan test                   # Pest suite
php artisan biztrack:scan-permits  # the daily compliance scan, run by hand
```

Routes live in `routes/workflow.php`. The state machine lives in
`app/Services/WorkflowService.php`; controllers stay thin and every status
change goes through it. `docs/api-contract.md` at the repo root documents the
full surface; `docs/bruno/` holds a runnable request collection.
