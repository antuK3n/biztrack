# BizTrack — Requirements Traceability Audit

**Audit date:** 2026-07-24 (snapshot taken while the CONTRACT EXTENSION v2 wave — messaging, officer requests, scheduler, prefill, PDFs, OCR-lite — was landing in parallel; migrations/controllers for v2 had landed at close of audit, routes were not yet wired).
**Sources:** `GROUP 12 - REVISED PROPOSAL DEFENSE MANUSCRIPT.docx.pdf` (Table 4 R1–R21 functional, Table 5 R22–R33 non-functional, UCR-01…UCR-20, data dictionary Tables 27–61) vs. the actual code in `api/`, `web/`, `mobile/`, `r/`.
**Environment note:** dev runs **SQLite** (`api/.env DB_CONNECTION=sqlite`); the defended target is **PostgreSQL 16 + PostGIS** (master plan §2). Deviations from the manuscript schema are to be logged in `docs/schema-deltas.md` — **that file does not exist yet** and should be created before defense (it already has content owed to it: `message_attachments`, `permit_expiry_notices`, `businesses.status`, `business_owners` consolidation).

Legend: ✅ Built · 🟡 Partial · 🔨 In progress (this wave) · ❌ Missing · 🚫 Deferred-by-guardrail (master plan §9) · ⛔ Excluded (zoning — per team decision, no zoning data)

---

## 1. Functional requirements (Table 4, R1–R21)

| R# | Requirement | Status | Evidence / Gap |
|---|---|---|---|
| R1 | Registration + role-based login (5 roles) | ✅ | `api/routes/api.php` `/auth/*`; RBAC migrations `…000002–000005`; `web/src/pages/auth/RegisterPage.tsx`, `LoginPage.tsx` |
| R2 | Unified permit application, single platform | ✅ | `web/src/pages/applicant/ApplyWizard.tsx` (multi-permit-type intake); `POST /applications` in `api/routes/workflow.php` |
| R3 | Unique tracking ID per application | ✅ | `WorkflowService::submit()` → `Numbering::trackingId()` (`BIZ-YYYY-NNNNN`), `applications.tracking_id` unique |
| R4 | Upload & manage required documents | ✅ | `DocumentController` store/download routes; `…000031_create_application_documents_table.php` |
| R5 | Centralized digital permit vault | 🟡 | `web/src/pages/applicant/PermitsPage.tsx` + `PermitDetailPage.tsx` (QR via `qrcode.react`); `permits.pdf_path` column exists. Gap: `GET /permits/{id}/pdf` (dompdf certificate) is contract-v2, not yet routed |
| R6 | Real-time application status tracking | ✅ | `GET /applications/{id}/timeline` + `application_status_history` written on every `WorkflowService::transition()`; `ApplicationDetailPage.tsx` |
| R7 | Display assigned officer/department | ✅ | Assignments returned on application show; `ApplicationDetailPage.tsx` renders the officer line (L365) |
| R8 | Auto-route applications to departments | ✅ | `WorkflowService::routeToDepartments()` fires from `onPaymentCompleted()`; assignments per owning department |
| R9 | Messaging system (users ↔ LGU staff) | 🔨 | `…000060` creates `message_threads`/`messages`/`message_attachments`; `MessageController.php` exists. Gap: routes not wired in `workflow.php`; no thread UI yet |
| R10 | Compliance monitoring + expiry/renewal notifications | 🔨 | `…000062_create_permit_expiry_notices_table.php` landed; `biztrack:scan-permits` (60/30/7-day scans) specified in contract v2. Gap: command not yet registered in `routes/console.php` |
| R11 | Reports & analytics | 🟡 | `GET /analytics/summary` + `web/src/pages/admin/AnalyticsPage.tsx` (Recharts KPIs, line/bar charts). Gap: CSV/PDF exports are contract-v2 (unrouted); advanced analytics (SPC/DES) live only in the standalone `r/` prototype, not integrated |
| R12 | GIS-based mapping of registered businesses | 🟡 | Leaflet map of businesses with real `business_addresses` lat/lng on `AnalyticsPage.tsx` (`useBusinessMarkers`, L67). Gap: no dedicated map screen with barangay/status/type filters; markers sourced from the inspections feed only. Zoning overlay side is ⛔ excluded |
| R13 | Permit assistance by business type/requirements | ✅ | `permit_type_requirements` migration + `/reference/*` endpoints; wizard requirements checklist in `ApplyWizard.tsx` |
| R14 | Inspection scheduling: auto-assign by workload + dept override | ✅ | `WorkflowService::scheduleInspections()` (least-loaded inspector via `withCount`); `POST /inspections/{id}/reschedule` = override |
| R15 | Rule-based engine for permit validation | 🟡 | Status machine (`app/Enums`), requirement checklists, `compliance_checks`, payment gate act as the rule engine. Gap: zoning/PSIC rules ⛔ excluded (no zoning data) |
| R16 | AI chatbot (logged history, intent, handover) | 🚫 | Deferred by master plan §9 guardrail. Schema honored: `…000064_create_chatbot_tables.php` (dormant, no endpoints/UI) |
| R17 | Cashless payment for permit fees | ✅ | `PaymentController` fee/pay/index routes; `app/Services/PaymentGateway.php` simulated driver (gcash/maya/card, `PAY-` refs) per plan §5.5 — real gateway intentionally out of scope |
| R18 | Payment validated before processing continues | ✅ | `pending_payment → under_review` only fires in `WorkflowService::onPaymentCompleted()`; routing happens after |
| R19 | OCR-based document processing | 🔨 | `smalot/pdfparser` in `api/composer.json`; OCR-lite (text-layer suggestions on DTI docs) specified in contract v2. Gap: not yet visible in `DocumentController` response |
| R20 | Centralized records for applications & permits | ✅ | Single relational schema (33 migration files) + audit trail (`…000007_create_audit_logs_table.php`, `Audit::log` calls in services) |
| R21 | Officer ad hoc requests (documents, messages, **meetings**) | 🔨 | `…000061_create_officer_requests_table.php` + `OfficerRequestController.php` landed. Gaps: routes/UI not wired; contract v2 scopes `request_type` to `document|message` — the paper's **scheduled online meetings** (Jitsi) are not in this wave |

## 2. Non-functional requirements (Table 5, R22–R33)

| R# | Requirement | Status | Evidence / Gap |
|---|---|---|---|
| R22 | Easy-to-use, user-friendly UI | 🟡 | Full design system (`PRODUCT.md`, `DESIGN.md`, WCAG 2.1 AA target); coherent web UI across 20+ pages. Gap: no usability-test evidence yet (UAT is Sprint 7) |
| R23 | Fast response during processing/retrieval | 🟡 | Paginated endpoints, SQL aggregates for analytics. Gap: no load/perf measurements; dev DB is SQLite, not the Postgres target |
| R24 | Accessible across desktop, mobile, tablets | 🟡 | Responsive web SPA built. Gap: `mobile/App.tsx` is a bare Expo scaffold ("Open up App.tsx…") — native app 🔨 being built in parallel, nothing functional yet |
| R25 | High reliability & availability | 🟡 | Queue/scheduler architecture + offline-LAN defense mode designed (plan §4). Gap: no monitoring, no failover, single-box compose |
| R26 | Secure authN + role-based access control | ✅ | Sanctum bearer tokens, login throttle, hand-rolled RBAC `permission:{name}` middleware on every workflow route group |
| R27 | Secure storage of documents & sensitive data | 🟡 | Private local disk, policy-checked `GET /documents/{id}/download`, bcrypt. Gap: TLS/headers/rate-limit hardening pass is Sprint 7, not done |
| R28 | Data consistency & integrity across modules | ✅ | FK-constrained migrations, PHP enums as single status source, `DB::transaction` in `WorkflowService`, history rows on every transition |
| R29 | Scalability for users & data | 🟡 | Stateless API + queue design; Postgres target supports it. Gap: unproven — no scale testing; SQLite in dev |
| R30 | Maintainability (standards + documentation) | 🟡 | Extensive docs (`md/00–07`, `docs/api-contract.md`, graphify graph). Gap: automated test suite is effectively empty (`api/tests/Feature/ExampleTest.php` only) — the plan's Pest gate is not being met |
| R31 | External integrations (SMS, email, GIS, OCR, payments) | 🟡 | Payments simulated ✅; GIS via Leaflet/OSM ✅; email/SMS log-driver simulation + OCR are contract-v2 🔨; Expo push not started |
| R32 | Backup & recovery mechanisms | ❌ | No `spatie/laravel-backup` in `composer.json`, no backup schedule or restore drill (planned Sprint 7) |
| R33 | Data Privacy Act (RA 10173) compliance | 🟡 | `data_privacy_consent` captured at registration (`RegisterPage.tsx`, `PrivacyNoticeDialog.tsx`); private storage; audit log. Gap: no retention/breach/subject-rights documentation |

**Functional (R1–R21):** 11 ✅ · 3 🟡 · 4 🔨 · 1 🚫 · 0 ❌ (R12/R15 carry ⛔ zoning sub-scope)
**Non-functional (R22–R33):** 2 ✅ · 9 🟡 · 1 ❌

---

## 3. Use-case reports (UCR-01…UCR-20)

| UCR | Name | Status | Evidence / Gap |
|---|---|---|---|
| 01 | Register | ✅ | `POST /auth/register` (+ email verify, consent); `RegisterPage.tsx` |
| 02 | Login | ✅ | `POST /auth/login` (+ forgot/reset); `LoginPage.tsx` |
| 03 | Apply for Permit | ✅ | `ApplyWizard.tsx` → `POST /applications` → `/submit`; documents + fee assessment on submit |
| 04 | Renew Permit | 🟡 | `applications.application_type = renewal` + `prior_permit_id` in schema; wizard has a renewal mode (`ApplyWizard.tsx` L56, L287). Gap: `GET /businesses/{id}/prefill` (contract v2) not yet routed — renewal isn't prefilled from the last permit |
| 05 | Track Applications | ✅ | `ApplicationsPage.tsx` + `ApplicationDetailPage.tsx` + timeline endpoint |
| 06 | Manage Drafts | ✅ | `DraftsPage.tsx`; `status: draft` + `PUT /applications/{id}` + cancel |
| 07 | Communicate with Chatbot | 🚫 | Deferred by guardrail §9; tables dormant (see R16) |
| 08 | Communicate Online | 🔨 | Messaging tables + `MessageController` landed this wave; routes/UI pending. Residual gap: the paper's video-conferencing element (Jitsi meetings) is not in v2 |
| 09 | View Profile / Approved Permits | ✅ | `PermitsPage.tsx`, `PermitDetailPage.tsx` (QR), `GET /auth/me`, `GET /permits` |
| 10 | Edit Settings | 🟡 | `SettingsPage.tsx` exists (reads auth store). Gap: no profile-update endpoint in the API surface (`/auth` has no PUT me) — edits aren't persisted server-side |
| 11 | Apply for Amendment | 🟡 | `application_type = amendment` supported by schema + wizard mode. Gap: same prefill dependency as UCR-04 |
| 12 | View Other Requirements | 🔨 | `web/src/pages/RequestsPage.tsx` is explicitly a **static mock** ("no thread API yet", file header); backend (`officer_requests` + controller) landed this wave, wiring pending |
| 13 | View Payment History | ✅ | `GET /payments` + `PaymentsPage.tsx` (and `PayPage.tsx` for settlement) |
| 14 | Manage Applications (Office Admin) | ✅ | `QueuePage.tsx`, `ReviewPage.tsx`; assignment approve/return/checks + application reject routes |
| 15 | Manage Renewals | 🟡 | Renewals flow through the same officer queue/review (one status machine for all types). Gap: no renewal-specific management view or prefill yet |
| 16 | Manage Approved Permits | 🟡 | `GET /permits` supports `permit.view_all`. Gap: no officer-side permits management page (only owner vault); revoke/suspend actions not exposed |
| 17 | Manage Amendments | 🟡 | Same as UCR-15 — handled generically by the queue, no dedicated view |
| 18 | Create Other Requirements | 🔨 | `OfficerRequestController.php` + migration landed; `POST /applications/{id}/requests` route + officer UI not yet wired |
| 19 | Manage Officer-in-Charge | 🔨 | `WorkflowService::assignOfficer()` (dept-checked) exists; `POST /assignments/{id}/assign` route not yet wired; `admin/UsersPage.tsx` covers staff accounts |
| 20 | Manage Business Owner Status | 🟡 | `POST /admin/users/{id}/toggle-active` + `OwnersPage.tsx` built; `businesses.status` migration (`…000063`) landed. Gap: `POST /admin/businesses/{id}/status` (flag/suspend/blacklist + application block) not yet routed |

**UCRs:** 8 ✅ · 7 🟡 · 4 🔨 · 1 🚫 · 0 ❌

---

## 4. Data dictionary — 35 manuscript tables vs. migrations

**30 of 35 exist as migrations** (33 migration files at audit close; `…000060` creates 2 dictionary tables + 1 extra, `…000064` creates 2).

| Manuscript table (Tables 27–61) | Migration |
|---|---|
| barangays | `2026_07_24_000010_create_barangays_table.php` |
| psic_codes | `2026_07_24_000011_create_psic_codes_table.php` |
| departments | `2026_07_24_000001_create_departments_table.php` |
| permit_types | `2026_07_24_000012_create_permit_types_table.php` |
| document_types | `2026_07_24_000013_create_document_types_table.php` |
| roles | `2026_07_24_000002_create_roles_table.php` |
| permissions | `2026_07_24_000003_create_permissions_table.php` |
| role_permissions | `2026_07_24_000004_create_role_permissions_table.php` |
| users | `0001_01_01_000000_create_users_table.php` (extended) |
| user_roles | `2026_07_24_000005_create_user_roles_table.php` |
| businesses | `2026_07_24_000020_create_businesses_table.php` (+ `…000063` adds `status`) |
| business_addresses | `2026_07_24_000022_create_business_addresses_table.php` (lat/lng decimals) |
| business_lines | `2026_07_24_000023_create_business_lines_table.php` |
| applications | `2026_07_24_000030_create_applications_table.php` |
| application_documents | `2026_07_24_000031_…` |
| application_status_history | `2026_07_24_000032_…` |
| application_assignments | `2026_07_24_000033_…` |
| compliance_checks | `2026_07_24_000036_…` |
| permits | `2026_07_24_000041_…` |
| inspections | `2026_07_24_000040_…` |
| fee_assessments | `2026_07_24_000034_…` |
| payments | `2026_07_24_000035_…` |
| message_threads | `2026_07_24_000060_create_message_threads_table.php` (this wave) |
| messages | same migration `…000060` (this wave) |
| notifications | `2026_07_24_000050_…` |
| audit_logs | `2026_07_24_000007_…` |
| officer_requests | `2026_07_24_000061_…` (this wave) |
| permit_type_requirements | `2026_07_24_000014_…` |
| chatbot_conversations | `2026_07_24_000064_create_chatbot_tables.php` (dormant, this wave) |
| chatbot_messages | same migration `…000064` (dormant, this wave) |

**Missing (5) and why:**

| Table | Why absent |
|---|---|
| zone_classifications | ⛔ Zoning excluded — team decision, no official zoning data |
| zones | ⛔ Zoning excluded |
| zone_allowed_psic | ⛔ Zoning excluded |
| zoning_evaluations | ⛔ Zoning excluded |
| business_owners | Consolidated: owner is `businesses.owner_user_id → users` FK (`…000020` L13). Must be logged in `docs/schema-deltas.md` |

**Beyond-dictionary tables** (need `schema-deltas.md` entries): `message_attachments`, `permit_expiry_notices`, Laravel infrastructure tables (cache, jobs, personal_access_tokens).

---

## 5. Defense risks — top 5 panel questions and honest answers

1. **"Your manuscript defends zoning validation (R12/R15, 4 tables) — where is it?"** — Honest answer: the city could not release official zoning polygons, so per team decision we excluded zoning data entirely rather than demo fabricated boundaries; the schema and `ST_Contains` design are documented and the module drops in when MISD provides GeoJSON.
2. **"Where is the AI chatbot (R16 / UCR-07)?"** — Honest answer: it was consciously deferred by our build guardrails; the `chatbot_conversations`/`chatbot_messages` tables exist dormant so the 35-table schema holds, and the conversation/handover design is documented, but there is no runtime — we prioritized the permit workflow core.
3. **"UCR-08/UCR-18 promise online communication and ad hoc requests — the requests screen is static."** — Honest answer: messaging and officer-request backend (tables, controllers) landed in the current gap-closure wave and route/UI wiring is the immediate next task; the Jitsi meeting type specifically is descoped from this wave and we will present it as designed-not-built.
4. **"How do you demonstrate quality — where are your tests, backups, and Postgres?"** — Honest answer: dev currently runs SQLite with the Pest suite still skeletal and laravel-backup not yet installed (R32 is our one outright miss); these are Sprint-7 hardening items scheduled before UAT, and the API was built contract-first so the switch to Postgres is a config change plus the deltas file.
5. **"The paper claims mobile/tablet accessibility (R24) — where is the mobile app?"** — Honest answer: the Expo project is scaffolded and being built in parallel, but today only the responsive web SPA is functional; defense demo runs web plus the public QR verify page on a phone browser, with the native app as in-progress work.

---

*Prepared for grading prep — statuses reflect verified code state (routes, migrations, pages), not plan intent. Where the master plan's §8 map disagreed with code, code won.*
