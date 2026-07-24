# Schema deltas

Post-v1 schema changes to existing tables (new tables are self-documenting in their
migrations). The full paper-vs-schema matrix lives in `db-solidification.md`; this file
is the running changelog of concrete deltas.

- `businesses.status` — string, default `active` (values: active|flagged|suspended|blacklisted). Added by `2026_07_24_000063_add_status_to_businesses_table`. Suspended/blacklisted businesses are blocked from `POST /applications`.

## Manuscript alignment (2026-07-24)

Renames (edited in the base create migrations; `migrate:fresh` is the workflow pre-defense):

- `roles.label` → `roles.display_name` (paper Table 32). Updated: `Role` model, `RbacSeeder`.
- `permit_types.department_id` → `permit_types.issuing_department_id` (paper Table 30). Updated: `PermitType` model (`department()` relation aliased), `ReferenceSeeder`, `WorkflowService` routing + inspection scheduling.
- `permit_type_requirements.is_required` → `is_mandatory` (paper Table 59). Reference resource emits `is_mandatory` **and** legacy `is_required`.
- `officer_requests` migrated to the full paper Table 58 shape: `created_by_user_id`→`requested_by_user_id`, `subject`→`title`, `body`→`description`, `response_body`→`applicant_response`, `responded_at`→`submitted_at`; added `department_id`, `due_date`, `file_name`, `file_path`, meeting fields (`meeting_scheduled_at`, `meeting_duration_minutes` default 30, `meeting_link`, `meeting_platform` default `google_meet`, `external_calendar_event_id`), `reviewed_by_user_id`, `reviewed_at`, `remarks`. **Compat shim:** controller accepts `subject|title` and `body|description`; resource emits both names. Meeting requests store officer-provided fields only — no live Google Calendar call (future work).

Added columns (paper dictionary fields; nullable/defaulted, non-breaking):

- `departments.description` (Table 29).
- `permit_types.validity_days` (default 365), `permit_types.description` (Table 30). `WorkflowService::approveAndIssue` now sets `valid_until = valid_from + validity_days` (replaces end-of-year).
- `document_types`: `description`, `is_required`, `file_size_max_mb` (10), `accepted_formats` (Table 31).
- `users`: `last_login_at`, `failed_login_attempts` (0), `locked_until` (Table 35). `AuthController::login` stamps `last_login_at` and persists failed-attempt / lockout state. Framework delta: paper's `password_hash` is Laravel's `password` (kept).
- `permit_type_requirements`: `context` (default `all`), `notes` (Table 59).
- `businesses`: BPLO-form fields — `form_of_organization`, `economic_organization(+_others)`, `president_officer_name`, `citizenship`, `capital_participation_filipino`, `capital_investment`, `business_area_sqm`, `total/male/female_employees`, `employees_within_lgu`, `delivery_units`, `has_tax_incentives`, `pays_rent` (Table 40).
- Sweep migration `..000073_align_tables_with_manuscript`: `barangays.code`; `psic_codes.category`; `business_addresses` (address_type, house_bldg_no, street, telephone, mobile_number, email, website); `business_lines` (line_of_business, products_services, gross_sales); `applications` (has_amendments, amendment_*, mode_of_payment, complexity, date_received, received_by_user_id, data_privacy_consent); `application_documents` (file_hash, uploaded_by_user_id, verification_status, verified_at, verified_by_user_id, rejection_reason); `application_status_history.department_id`; `permits` (qr_code, document_hash, revoked_at, revoked_reason); `inspections` (inspection_type, location_address, latitude, longitude); `fee_assessments` (assessed_by_user_id, assessed_at); `messages` (attachment_path, read_at); `message_threads` (subject, status); `app_notifications` (data, channel, sent_at); `audit_logs.user_agent`.

New tables:

- `business_owners` (`..000072`, paper Table 41) — personal owner details; coexists with `businesses.owner_user_id` (auth linkage). Dual representation documented in `db-solidification.md`.
- `application_office_forms` (`..000074`) — per-office application form payloads, **UI prototype Parts 4–7 (pages 40–44)**; dictionary has no equivalent, stored as JSON keyed by `[application_id, permit_type_id]`. Endpoints: `GET /applications/{id}/office-forms`, `PUT /applications/{id}/office-forms/{permitTypeCode}` (owner, draft/returned only, ≤16KB).

Documented deltas (kept as-is, not migrated):

- Table name: `app_notifications` (paper `notifications`) — avoids colliding with Laravel's notifications; model `$table` pins it.
- `psic_codes.title` used for paper `description` (compat).
- `compliance_checks` per-checklist-item model vs. paper per-requirement grid (same intent, finer granularity).
- `fee_assessments` denormalized (`line_items` JSON + `total_amount`) vs. paper's one-row-per-fee.
- Zoning tables (Tables 37–39, 49) and `business_addresses.zone_id` — **excluded** (team decision, no zoning layer this cycle). CPDO stays seeded as a department.
- chatbot tables (Tables 60–61) — dormant schema only; paper's richer fields not yet modeled.

Reference-data change: six permit types seeded (BUSINESS, SANITARY, FSIC + OCCUPANCY, CEC, MARKET) per the UI prototype LGU Section (pages 37–38); the manuscript names three. Offices OBO/CENRO/CMO-MARKET added with descriptions + demo officer accounts and review-set roles (`obo_staff`, `cenro_officer`, `market_admin`). Zoning clearance excluded as a permit type.
