# Database Solidification — Manuscript Alignment (Friday review)

This document reconciles the BizTrack API database (`api/database/migrations`) with
the capstone manuscript's Data Dictionary (Tables 27–61). It is the defensible,
table-by-table record for the teammate review.

## Precedence rule (source-of-truth order)

When sources conflict, we resolve in this order:

1. **UI prototype** ("BizTrack Prototype Linked.pdf" / `prototype-pages/`) — the most
   recent team agreement.
2. **Capstone manuscript** (`/tmp/manuscript.txt`, Data Dictionary Tables 27–61).
3. **Sprint plans** (`md/00-master-plan.md` … `md/07-*`).

Field-level alignment follows the manuscript; scope decisions (which offices/permit
types exist) follow the prototype where it is more current.

## Permit-type scope (the "missing offices" question)

- The **manuscript body** scopes the unified application to exactly three permits:
  *"business permits, sanitary permits, and fire safety inspection certificates into a
  single application"*. Manuscript Table 30 therefore names **BUSINESS / SANITARY /
  FSIC** only.
- The **UI prototype LGU Section (p37, "Part 3 of 8")** shows **six** permit/clearance
  cards: Occupancy Permit (OBO), Sanitary Permit/Health Certificate (CHO), City
  Environmental Certificate (CENRO), Market Clearance (CMO-MARKET), Fire Safety
  Inspection Certificate (BFP), and Zoning Clearance (Planning/Zoning Office).
- **Decision:** seed **six** permit types — the manuscript's three **plus** OCCUPANCY,
  CEC, MARKET (additive, non-breaking). These are the most-recent team agreement.
  **Zoning Clearance stays excluded as a permit type** (no zoning dataset — team
  decision), but **CPDO remains a seeded department**. Routing fans out per
  `permit_types.issuing_department_id`, so a six-type application automatically hits all
  six office queues (verified by the `WorkflowHappyPathTest` 6-office test).

## Added LGU offices (with descriptions, per Table 29)

Seeded in `ReferenceSeeder`, each with a `description`:

| code | name |
|------|------|
| BPLO | Business Permits and Licensing Office |
| CHO | City Health Office |
| BFP | Bureau of Fire Protection |
| CPDO | City Planning and Development Office (Zoning) — dept only, no permit type |
| **OBO** | Office of the Building Official |
| **CENRO** | City Environment and Natural Resources Office |
| **CMO-MARKET** | Office of the City Market Administrator |

## Actor → role mapping (Table 32/33)

The manuscript's three actor classes map onto our granular RBAC roles (a refinement of
its "Office Admin"):

- **Business Owner** → `business_owner`
- **Office Admins** (BPLO + related offices) → `bplo_staff`, `sanitary_officer`,
  `fire_inspector`, `zoning_officer`, `obo_staff`, `cenro_officer`, `market_admin`
- **Super Admin** → `admin`

`zoning_officer` is seeded with a review/view-only permission set (no `permit.issue`,
no `fee.adjust`). Every role now carries `display_name` + `description` (Table 32).

## business_owners — dual representation (Table 41)

The manuscript defines `business_owners` as a **separate table** of personal owner
details (surname/given_name/middle_name/suffix/gender/is_primary, FK to businesses),
supporting multiple owners for partnerships/cooperatives. We **created it** and populate
rows in the seeders. We also **keep `businesses.owner_user_id`** for app auth/ownership
logic. Both coexist by design: `owner_user_id` is the login-linked account; a
`business_owners` row is the BPLO-form owner identity. Documented here as intentional.

## Framework deltas (kept on purpose)

- **`users.password`** — the manuscript names the column `password_hash`; we keep
  Laravel's `password` (bcrypt via the `hashed` cast). Semantics identical; column name
  is a framework convention.
- **`users.name`, `users.data_privacy_consent_at`** — app-level convenience columns not
  in the dictionary; harmless additions.
- **`notifications`** — the physical table stays `app_notifications` (model
  `$table = 'app_notifications'`). Renaming this close to defense is a breaking change we
  chose to defer; recorded as a documented delta (see Table 56 row).

---

## Table-by-table matrix

Status legend: **aligned** · **aligned+extras** (paper fields present, we add impl
columns) · **renamed** (column renamed to match paper) · **delta** (documented
divergence) · **added** (missing paper columns added) · **excluded** (team decision).

| # | Paper table | Migration file | Status | Notes |
|---|-------------|----------------|--------|-------|
| 27 | barangays | `..000010_create_barangays_table` (+ `..000073` sweep) | aligned+extras | Added `code` (official barangay code). Paper has no `province/region` (fixed for Malabon) — matches. `name` is `unique`. |
| 28 | psic_codes | `..000011_create_psic_codes_table` (+ `..000073`) | delta | Paper `description`+`category`; we use `title` for the description text and **added** `category`. `title`↔`description` is a naming delta. |
| 29 | departments | `..000001_create_departments_table` | added | **Added `description`.** Seeded 7 depts incl. OBO/CENRO/CMO-MARKET. |
| 30 | permit_types | `..000012_create_permit_types_table` | renamed+added+extras | **Renamed** `department_id`→`issuing_department_id`; **added** `validity_days` (default 365), `description`. Extras kept: `permit_number_prefix`, `requires_inspection`, `base_fee`, `per_line_surcharge`. `WorkflowService::approveAndIssue` now computes `valid_until = valid_from + validity_days`. |
| 31 | document_types | `..000013_create_document_types_table` | added+extras | **Added** `description`, `is_required`, `file_size_max_mb` (10), `accepted_formats` (pdf,jpg,jpeg,png). Extra: `help_text` (plain-language guidance for the wizard). |
| 32 | roles | `..000002_create_roles_table` | renamed | **Renamed** `label`→`display_name`; `description` present, now populated. |
| 33 | permissions | `..000003_create_permissions_table` | delta | Paper has `description` (nullable); ours stores `name` only (permission strings are self-describing and match the web mock). Non-breaking delta. |
| 34 | role_permissions | `..000004_create_role_permissions_table` | aligned | Junction (role_id, permission_id). |
| 35 | users | `..000000_create_users_table` (+ `..000070`) | added+delta | **Added** `last_login_at`, `failed_login_attempts` (0), `locked_until`; `AuthController::login` now stamps `last_login_at` and persists failed-attempt/lock state (mirrors the rate limiter). Deltas: `password` (vs `password_hash`), plus app extras `name`, `data_privacy_consent_at`. |
| 36 | user_roles | `..000005_create_user_roles_table` | delta | Paper adds `assigned_at`; ours uses standard timestamps. Non-breaking. |
| 37–39 | zone_classifications / zones / zone_allowed_psic | — | **excluded** | Zoning tables excluded by team decision (no zoning dataset). CPDO department kept. |
| 40 | businesses | `..000020` (+ `..000071` BPLO fields, `..000063` status) | added+extras | **Added** the full BPLO-form set (`form_of_organization`, `economic_organization(+_others)`, `president_officer_name`, `citizenship`, `capital_participation_filipino`, `capital_investment`, `business_area_sqm`, `total/male/female_employees`, `employees_within_lgu`, `delivery_units`, `has_tax_incentives`, `pays_rent`) + `status`. Deltas: app uses `name`/`registration_type`/`registration_number`/`ban`/`is_active` vs paper `business_name`/`dti_sec_cda_number`/`business_account_number`. |
| 41 | business_owners | `..000072_create_business_owners_table` | added (new table) | **Created** per dictionary; seeded rows. Coexists with `owner_user_id` (see dual-representation note). |
| 42 | business_addresses | `..000022` (+ `..000073`) | added+extras | **Added** `address_type` (default business_location), `house_bldg_no`, `street`, `telephone`, `mobile_number`, `email`, `website`. Extras: `line1/line2`, `city`/`province` (defaulted for Malabon). Paper `zone_id` **excluded** (zoning). |
| 43 | business_lines | `..000023` (+ `..000073`) | added+extras | **Added** `line_of_business`, `products_services`, `gross_sales`. Extra: `capitalization`. |
| 44 | applications | `..000030` (+ `..000073`) | added+delta | **Added** `has_amendments`, `amendment_*`, `mode_of_payment`, `complexity`, `date_received`, `received_by_user_id`, `data_privacy_consent`. Deltas: `applicant_user_id` (derived applicant FK), `decided_at` vs paper `approved_at`/`rejected_at`. `application_permit_types` M:N is our unified-application mechanism. |
| 45 | application_documents | `..000031` (+ `..000073`) | added+delta | **Added** `file_hash`, `uploaded_by_user_id`, `verification_status` (pending), `verified_at`, `verified_by_user_id`, `rejection_reason`. Naming deltas: `original_filename`↔`file_name`, `stored_path`↔`file_path`, `size_bytes`↔`file_size_bytes`. |
| 46 | application_status_history | `..000032` (+ `..000073`) | added+delta | **Added** `department_id`. Delta: `note`↔`remarks` (kept `note`, both free text). |
| 47 | application_assignments | `..000033` | delta | Paper `assigned_user_id`; ours `officer_user_id`. Otherwise aligned (department_id, status, assigned_at, completed_at, remarks). |
| 48 | compliance_checks | `..000036` | delta | Our model is a per-assignment checklist grid (`application_assignment_id`, `label`, `is_checked`, `note`) rather than the paper's per-application (`description`, `compliance_status`, `evaluated_by/at`). Same intent (Annex-1 verification), different shape. Documented divergence. |
| 49 | zoning_evaluations | — | **excluded** | Zoning (team decision). |
| 50 | permits | `..000041` (+ `..000073`) | added+extras | **Added** `qr_code`, `document_hash`, `revoked_at`, `revoked_reason`. Extra: `pdf_path`↔paper `file_path`. Aligned: permit_number, valid_from/until, status, issued_at, issued_by_user_id. |
| 51 | inspections | `..000040` (+ `..000073`) | added+extras | **Added** `inspection_type`, `location_address`, `latitude`, `longitude`. Aligned: department_id, inspector_user_id, scheduled/conducted_at, status, result, findings, photo_paths. |
| 52 | fee_assessments | `..000034` (+ `..000073`) | added+delta | **Added** `assessed_by_user_id`, `assessed_at`. Delta: we store `line_items` (JSON) + `total_amount` per application (one row) rather than the paper's one-row-per-fee (`fee_type`, `amount`, `description`). Kept `adjusted_by_user_id` for the fee-adjust feature. |
| 53 | payments | `..000035` | delta | Aligned: reference_number, amount, status, paid_at, receipt_path. Deltas: `method`↔`payment_method`, plus our `fee_assessment_id` FK. |
| 54 | message_threads | `..000060` (+ `..000073`) | added | **Added** `subject`, `status` (open). Aligned: application_id (unique, 1/app). |
| 55 | messages | `..000060` (+ `..000073`) | added+delta | **Added** `attachment_path`, `read_at`. Delta: attachments also modeled richly in `message_attachments` (our extra table). `sender_user_id`, `body` aligned. |
| 56 | notifications | `..000050_create_notifications_table` | delta+added | Physical table is `app_notifications`; `AppNotification::$table = 'app_notifications'`. **Added** `data` (JSON), `channel` (in_app), `sent_at`. `title`/`body`/`read_at`/`type`/`user_id` aligned. Rename to `notifications` deferred (breaking) — documented delta. |
| 57 | audit_logs | `..000007` (+ `..000073`) | added+delta | **Added** `user_agent`. Deltas: `auditable_type`/`auditable_id`↔`entity_type`/`entity_id`; single `changes` JSON vs `old_values`/`new_values`; `action` aligned. |
| 58 | officer_requests | `..000061_create_officer_requests_table` | renamed+added | Migrated to the full paper shape: **renamed** `created_by_user_id`→`requested_by_user_id`, `subject`→`title`, `body`→`description`, `response_body`→`applicant_response`, `responded_at`→`submitted_at`; **added** `department_id`, `due_date`, `file_name`, `file_path`, meeting fields (`meeting_scheduled_at`, `meeting_duration_minutes` default 30, `meeting_link`, `meeting_platform` default google_meet, `external_calendar_event_id`), `reviewed_by_user_id`, `reviewed_at`, `remarks`. `request_type` supports `document`/`message`/`meeting`. **Compat shims:** controller accepts `subject|title` and `body|description`; resource emits BOTH names. Calendar integration is future work — meeting fields are officer-provided only. |
| 59 | permit_type_requirements | `..000014` | renamed+added | **Renamed** `is_required`→`is_mandatory`; **added** `context` (all/new/renewal/occupancy/business_permit, default all) and `notes`. Seeder sets renewal-only docs (PRIOR_PERMIT) to `context=renewal`, occupancy docs to `context=occupancy`. Reference resource emits both `is_mandatory` and legacy `is_required`. |
| 60 | chatbot_conversations | `..000064_create_chatbot_tables` | delta (dormant) | Schema-only per master plan §9. Paper adds `session_token`, `ended_at`, `handover_status`, `handed_over_to/at`, `expires_at`; ours is a minimal dormant stub (user_id, started_at). No endpoints. Documented delta. |
| 61 | chatbot_messages | `..000064_create_chatbot_tables` | delta (dormant) | Paper adds `role`, `detected_intent`, `confidence_score`, `metadata`; ours is a minimal stub (sender, body). Dormant. |

### Additional (no manuscript equivalent)

| Table | Migration | Notes |
|-------|-----------|-------|
| application_office_forms | `..000074_create_application_office_forms_table` | Per-office application form payloads (UI prototype Parts 4–7, pages 040–044). Opaque JSON keyed by (application, permit_type). GET/PUT endpoints in `routes/workflow.php`. No dictionary equivalent. |
| message_attachments | `..000060` | Richer message-attachment modeling (our extra). |
| permit_expiry_notices | `..000062` | Dedupe ledger for the expiry scheduler (our extra). |
| application_permit_types | `..000030` | M:N unified-application join (our mechanism). |

## officer_requests API compatibility shims

The web/mobile clients (built in parallel per `docs/api-contract.md`) send and expect
`subject`/`body`. To avoid breaking them while matching the paper schema:

- **Controller (`store`)** accepts `title` OR `subject`, and `description` OR `body`.
- **Resource** emits BOTH: `title`+`subject` (same value), `description`+`body`,
  `applicant_response`+`response_body`, `submitted_at`+`responded_at`.
- **`request_type: meeting`** is accepted at the API level: `meeting_scheduled_at`,
  `meeting_duration_minutes`, `meeting_link`, `meeting_platform` are stored as
  officer-provided fields. **No Google Calendar API call is made** — calendar
  integration is future work.

## Quality gates (plan §5.6)

- **Tests:** Pest feature suite (`api/tests/Feature`) — auth (ok/bad/deactivated), full
  workflow happy path (create→submit→pay→3 approvals→inspections→permits with
  validity_days), 6-office fan-out, authorization negatives (cross-owner, cross-dept,
  permission), and v2 endpoints (messages, requests incl. meeting, fee adjust, business
  status block, prefill, office-forms). `php artisan test` green (15 passed).
- **Bruno:** `docs/bruno/` — folder-per-module `.bru` files covering every
  `routes/workflow.php` route plus auth, with a `local` environment (baseUrl + token).

## Backups (R32) — restore drill

BizTrack uses `spatie/laravel-backup`.

- **Config** (`config/backup.php`): files include `storage/app/private` + the SQLite
  database file; database dump of the `sqlite` connection; notifications disabled (no
  live SMTP/Slack in the prototype).
- **Schedule** (`routes/console.php`): `backup:clean` daily 01:30, `backup:run` daily
  02:00.
- **Verified:** `php artisan backup:run` completed successfully (zip written to
  `storage/app/BizTrack/…`, ~1.7 MB, 13 files + db dump).

**Restore drill (SQLite):**

1. Locate the latest backup zip: `ls -t storage/app/BizTrack/*.zip | head -1`.
2. Unzip to a scratch dir: `unzip <backup>.zip -d /tmp/biztrack-restore`.
3. The archive contains `db-dumps/` (a `.sql` dump of the sqlite connection) and the
   included files tree.
4. Restore the database: stop the app, then
   `sqlite3 database/database.sqlite < /tmp/biztrack-restore/db-dumps/*.sql`
   (or copy back the included `database.sqlite` from the files tree).
5. Restore uploads by copying the archived `storage/app/private/*` back into
   `storage/app/private/`.
6. Sanity check: `php artisan migrate:status` (all migrations present) and log in with a
   seeded demo account.
