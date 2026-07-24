# BizTrack API v1 — Contract (implementation-facing)

Base: `/api/v1`. Auth: `Authorization: Bearer <token>` (Sanctum). Success:
`{ "data": ..., "meta"? }`. Errors: Laravel shape `{ "message", "errors"? }`.
Money is PHP decimals. Times ISO-8601 UTC.

Already built: auth (`/auth/*`), all Eloquent models + enums, `WorkflowService`,
`PaymentGateway` (simulated), `NotificationService`, `Numbering`, `Audit`,
`permission:{name}` middleware. This doc defines the REST surface to build on top.

## Permission gates (names are canonical; match web mock)
owner: `business.manage_own application.create application.view_own document.upload_own payment.make permit.view_own`
officer/staff: `application.view_all application.review inspection.manage permit.view_all permit.issue`
admin adds: `analytics.view user.manage owner.manage_status oic.assign reference.manage audit.view`

## Endpoints

### Reference (auth required; read-only lookups for the wizard)
- `GET /reference/barangays` → `[{id,name}]`
- `GET /reference/psic-codes` → `[{id,code,title}]`
- `GET /reference/departments` → `[{id,code,name}]`
- `GET /reference/document-types` → `[{id,code,name,help_text}]`
- `GET /reference/permit-types` → `[{id,code,name,permit_number_prefix,department:{code,name},requires_inspection,base_fee,per_line_surcharge,document_types:[{id,code,name,help_text,is_required}]}]`

### Businesses (owner: manage_own; officers may view via application)
- `GET /businesses` (own) · `POST /businesses` · `GET /businesses/{id}` · `PUT /businesses/{id}`
  Body: `{name,trade_name?,registration_type?,registration_number?,tin?, address:{line1,line2?,barangay_id,latitude?,longitude?}, lines:[{psic_code_id,capitalization?}]}`
  Resource: `{id,name,trade_name,registration_type,registration_number,tin,ban,is_active,address:{...,barangay:{id,name}},lines:[{id,psic_code:{id,code,title},capitalization}]}`

### Applications
- `GET /applications` — owner sees own; `application.view_all` sees all. Query `?status=&type=&q=`. List resource: `{id,tracking_id,application_type,status,status_label,business:{id,name},submitted_at,deadline_at,permit_types:[{code,name}],created_at}`
- `POST /applications` — create DRAFT. Body `{business_id, application_type, permit_type_ids:[]}` (business may be created inline via the businesses endpoint first). Returns full ApplicationResource.
- `GET /applications/{id}` — full resource: adds `applicant:{id,name}`, `documents:[{id,document_type:{code,name},original_filename,size_bytes,created_at,download_url}]`, `fee_assessment:{line_items,total_amount}`, `payments:[PaymentResource]`, `assignments:[AssignmentResource]`, `inspections:[InspectionResource]`, `permits:[PermitResource]`, `rejection_reason`.
- `PUT /applications/{id}` — edit draft only (business/permit types).
- `POST /applications/{id}/submit` — `WorkflowService::submit`. Owner only, draft only.
- `POST /applications/{id}/resubmit` — returned → under_review (`WorkflowService::resubmit`). Owner.
- `POST /applications/{id}/cancel` — owner, allowed until payment. status→cancelled.
- `GET /applications/{id}/timeline` — `[{from_status,to_status,note,changed_by:{name}|null,created_at}]` from status history.

### Documents
- `POST /applications/{id}/documents` (multipart) — `document_type_id`, `file` (pdf/jpg/jpeg/png ≤10MB). Store `storage/app/private/documents/{app}/{uuid}.{ext}`, keep original name.
- `GET /documents/{id}/download` — policy-checked stream (owner or officer with view_all).

### Payments (owner: payment.make)
- `GET /applications/{id}/fee` → fee assessment `{line_items,total_amount}`.
- `POST /applications/{id}/pay` — body `{method: gcash|maya|card}`. Calls `PaymentGateway::charge` then `WorkflowService::onPaymentCompleted`. Returns PaymentResource `{id,reference_number,amount,method,status,paid_at}`.
- `GET /payments` — owner's payment history across applications.

### Officer queues + review (application.review)
- `GET /assignments` — the caller's department queue. Officer sees assignments for their `department_id`; admin sees all. Query `?status=`. AssignmentResource: `{id,status,status_label,remarks,department:{code,name},officer:{id,name}|null,assigned_at,completed_at, application:{id,tracking_id,business:{name},application_type,status}}`
- `GET /assignments/{id}` — adds the full application (documents, checklist).
- `POST /assignments/{id}/approve` — body `{remarks?}` → `WorkflowService::approveAssignment`.
- `POST /assignments/{id}/return` — body `{remarks}` (required) → `returnAssignment`.
- `POST /applications/{id}/reject` — body `{reason}` → `rejectApplication`. Gate `application.review`.
- Compliance checklist (optional): `POST /assignments/{id}/checks` toggle items.

### Inspections (inspection.manage)
- `GET /inspections` — caller's department (or own if inspector). Query `?status=`. InspectionResource `{id,status,status_label,result,result_label,scheduled_at,conducted_at,findings,department:{code,name},inspector:{id,name}|null, application:{id,tracking_id,business:{name}, address:{line1,barangay:{name},latitude,longitude}}}`
- `GET /inspections/{id}`
- `POST /inspections/{id}/conduct` — body `{result: passed|failed|conditional, findings?, photos?}` → `WorkflowService::recordInspection`.
- `POST /inspections/{id}/reschedule` — body `{scheduled_at}`.

### Permits
- `GET /permits` — owner sees own (via business), `permit.view_all` sees all. PermitResource `{id,permit_number,status,status_label,valid_from,valid_until,days_until_expiry,permit_type:{code,name},business:{id,name},application:{id,tracking_id},verify_url}`. `verify_url = {FRONTEND_URL}/verify/{permit_number}`.
- `GET /permits/{id}`
- **PUBLIC** `GET /verify/{permit_number}` — no auth. Returns `{permit_number,status,status_label,valid_from,valid_until,permit_type:{name},business:{name,address:{barangay:{name},city}}, is_valid}` or 404. This backs the public verify page (guardrail: no PII beyond business name/barangay).

### Notifications
- `GET /notifications` → `{data:[{id,type,title,body,link,read_at,created_at}], meta:{unread}}`
- `POST /notifications/{id}/read` · `POST /notifications/read-all`

### Analytics (analytics.view)
- `GET /analytics/summary` → `{applications_by_status:{status:count}, applications_by_type:{}, applications_by_month:[{month,count}], approval_rate, avg_processing_days, active_permits, expiring_permits, simulated_revenue}`

### Admin (user.manage)
- `GET /admin/users` · `POST /admin/users` (create officer w/ role+department) · `PUT /admin/users/{id}` · `POST /admin/users/{id}/toggle-active` (owner.manage_status).
- `GET /admin/audit-logs` (audit.view) → paginated `{data:[{action,user:{name},auditable_type,auditable_id,changes,created_at}]}`

## Conventions for the implementer
- Thin controllers → call `WorkflowService` for every state change. Never mutate `applications.status` directly.
- Authorization: use `permission:` middleware on routes + ownership checks in controllers (owner may only touch own business/applications).
- Register workflow routes in `routes/workflow.php` (auto-required by `routes/api.php`). Public verify route also lives there (outside the auth group).
- Eager-load to avoid N+1. Return API Resources, not raw models.
- Every new endpoint that mutates writes an `Audit::log(...)` (the service already does for workflow steps).

---
# CONTRACT EXTENSION v2 — gap-closure build (messaging, requests, scheduler, prefill, fees, OIC, owner status, PDFs, OCR-lite)

### Messaging (per-application thread; polling, no websockets)
- `GET /applications/{id}/messages` → `{data:[{id,body,sender:{id,name,is_officer},attachments:[{id,original_filename,download_url}],created_at]}` (participants: the applicant + any `application.view_all` holder). Backed by `message_threads` (1/application) + `messages` tables.
- `POST /applications/{id}/messages` — `{body}` (+ optional multipart `attachment`). Creates thread on first use. Notifies the other side.

### Officer requests (Other Requirements)
Tables `officer_requests` (application_id, created_by_user_id, request_type document|message, subject, body, status pending→submitted→fulfilled|rejected, response_body, responded_at).
- `POST /applications/{id}/requests` (permission `request.create`) `{request_type, subject, body}` → notifies applicant.
- `GET /requests` — owner: requests on own applications; officer: dept-visible/created. Resource: `{id,request_type,subject,body,status,status_label,created_by:{name,department},application:{id,tracking_id,business_name},response_body,created_at,responded_at}`.
- `POST /requests/{id}/respond` (owner, `request.respond`) `{body}` + optional multipart `document` (+`document_type_id`) → status submitted, notifies officer.
- `POST /requests/{id}/close` (officer) `{outcome: fulfilled|rejected}`.

### Scheduler (S6)
Artisan `biztrack:scan-permits`: (1) notify owners at 60/30/7 days before `valid_until` (dedupe per permit+threshold), (2) flip past-due active permits → `expired` + notify, (3) renewal-due notification when expired ≤30d ago. Registered daily in `routes/console.php`. Must be runnable manually for demo (`php artisan biztrack:scan-permits`).

### Renewal/amendment prefill (S4)
- `GET /businesses/{id}/prefill?type=renewal|amendment` (owner) → `{business:{...full}, last_permit:{id,permit_number,permit_type,valid_until}|null, last_application:{id,permit_type_ids}|null, suggested_permit_type_ids:[]}`.
- `POST /applications` now accepts optional `prior_permit_id` (stored).

### Fee adjustment + OIC (S3)
- `POST /applications/{id}/fee/adjust` (permission `fee.adjust`) `{line_items:[{label,amount}], total_amount}` → updates assessment, sets adjusted_by, audits, notifies owner if pending payment.
- `POST /assignments/{id}/assign` (permission `oic.assign`) `{officer_user_id, reason?}` → sets officer, audits. Officer must belong to the assignment's department.

### Business status (Owner Status page becomes real)
- Migration: `businesses.status` string default `active` (active|flagged|suspended|blacklisted) — log in schema-deltas.md.
- `POST /admin/businesses/{id}/status` (permission `owner.manage_status`) `{status, reason}` → audits with reason; blacklisted/suspended businesses are blocked from `POST /applications` (422 with plain-language message).
- `GET /admin/businesses` (same permission) → `{id,name,owner:{id,name},status,status_label,created_at}` list for the admin table.

### PDFs & export
- `GET /permits/{id}/pdf` — dompdf-rendered permit certificate (city header, permit no, business, validity, QR as embedded data-URI); stores `pdf_path`; policy: owner-of or `permit.view_all`.
- `GET /payments/{id}/receipt` — dompdf watermarked "SIMULATED PAYMENT" receipt; stores `receipt_path`; owner-of or officer.
- `GET /analytics/export` (permission `analytics.view`) → CSV download of the summary (status counts, monthly, KPIs).

### OCR-lite (R19, text-layer only)
- On `POST /applications/{id}/documents` for PDFs: parse text layer via smalot/pdfparser; if DTI/registration patterns found, response gains `ocr_suggestions: {business_name?, registration_number?, valid_until?}` — suggestions only, never auto-applied.

### Notifications channels (simulation pattern §5.5)
- `MAIL_MAILER=log` (emails render into laravel.log). `App\Services\Sms\SmsChannel` interface + `LogSmsChannel` writing `storage/logs/sms.log`; `SMS_DRIVER=log`. NotificationService fans out status-change + issuance + request events to in-app + mail + sms.

### Chatbot (dormant per plan §9 — tables only)
- Migrations for `chatbot_conversations`, `chatbot_messages`. No endpoints, no runtime.
