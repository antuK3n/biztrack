# BizTrack Web Rehaul — Prototype Fidelity Spec

Goal: the web app must LOOK like "BizTrack Prototype Linked.pdf". Page rasters live in
`prototype-pages/page-0NN.png` (2000×1125) with thumbs in `prototype-pages/thumbs/`.
Numbers below are PDF page references. The API and routes stay as-is; this is a visual +
structural rebuild of `web/`. The browser-chrome (back/forward/URL bar) in the mockups is
device framing — do NOT reproduce it.

## 1. Tokens

Colors (Tailwind-ish names → hex):
- `royal` #3242ca (sidebar, buttons, links, modal headers) · `royal-deep` #1d4b9e (pressed)
- `canvas` #d1dbeb (app background) · `panel` #b7c7e2 (deeper blue panels, e.g. drafts grid bg)
- `card` #ffffff · `input` #cfe0f7 (filled input bg) with 1px #9fb6dd border
- `ink` #14171d · `muted` #5b6472
- Status: `s-orange` #f2a33c (For Approval / Pending Payment) · `s-green` #22b573 (Approved/Paid)
  · `s-yellow` #f5c518 (For Inspection) · `s-red` #c11212 (Rejected/destructive) — red #bd0000 fine too
- Modal footer buttons: cancel #cfe0f7 / confirm #8fabd9 (text ink, underlined labels)

Type:
- Body/UI: **Poppins** (`@fontsource/poppins` 400/500/600/700)
- Display serif accents: **Prata** (`@fontsource/prata`) — used for: "Application Status",
  "Payment Status", "Approved Businesses", "LGU Section", "Tax Order of Payment",
  ₱ amounts in payment rows, "Reference No:". Sparingly, exactly where the prototype does.
- Logo: keep the real logo asset (`web/public` / Logo component).

Shape: cards `rounded-2xl` + soft shadow (`shadow-[0_2px_8px_rgba(30,50,120,.18)]`);
buttons **pill** (`rounded-full`) royal bg white text; small staff buttons `rounded-md`;
inputs `rounded-lg` filled; modals `rounded-none-ish` (blue header bar, square-ish corners ok).

## 2. App shell (owner + staff) — pages 5, 61

- Left rail: **80px wide**, full-height, `royal` bg. Items stacked: icon (white, 22px) with
  8px tiny white underlined label under it. Active item: white rounded-xl tile (48px) with
  royal icon, label below. Owner rail: Home, Track, Drafts, Payment History; avatar circle at
  bottom. Staff rail: Home, Track, Other Requirements; **logout icon at bottom**. Admin rail:
  Officer Assignment, Business Owner Status; logout at bottom.
- No topbar. Content sits directly on `canvas` with a **notification bell** top-right
  (royal, links /notifications). Page titles: bold Poppins 24–28 with a thin underline rule.
- **Chatbot bubble**: fixed bottom-right royal circle (56px) with white chat icon on ALL owner
  screens (opens a slide-in panel stub "BizTrack ChatBot" — page 8 — panel is a muted blue
  #8fa8d4 body with header bar; non-functional placeholder input is fine).
- Sort/Filter affordances top-right of list screens: `Sort ⇅  Filter ▽` text buttons (p14).

## 3. Modals — pages 12, 18, 35, 47, 62, 82

Centered, width ~560px: header bar (royal; red #c11212 for destructive/warning-red like
blacklist or inspection-rejection) with white bold uppercase-ish title; white body, centered
16px text; footer = two half-width blocks: light `#cfe0f7` Cancel + `#8fabd9` Confirm/Proceed,
labels underlined. Variants seen: WARNING (logout p18, clear-all p35, deactivate p98),
CONFIRMATION (submit p47), VERIFICATION (p73, p83 "Where would you like to go?" → Home/Tracking),
SUBMISSION w/ file input (p39), Data Privacy Consent (p26, long text, I Do Not Agree / I Agree),
green CONGRATULATIONS (p30) and red SORRY (p31) zoning results, REMARKS FOR REJECTION (p82,
red, complaint/description rows + green Add+).

## 4. Owner screens

- **Login (p1)**: split screen. Left ~55%: photo (`public/malaboncityhall.jpg`) with a
  #7796c5/60% blue overlay. Right: white panel, logo centered, "Email or number" + Password
  (filled inputs), royal pill "Sign In", "Forgot Password?" left-aligned small, below button:
  "Don't have an account? **Sign Up.**", footer link "BPLO Citizen Charter".
- **Sign Up (p3)**: same photo full-bleed; centered white rounded card: logo, 2-col
  First/Last name, Home Address full, Email/Contact 2-col, Password/Confirm 2-col, two
  checkboxes (terms + email/SMS consent), royal pill "Sign Up", "Already have an account? Sign In."
- **Home (p5)**: centered `Track your businesses with` (serif-ish display, 34px) + logo image
  under it; then 4 white shadow cards (icon 64px royal, label royal semibold): New Business
  Permit → /apply?type=new · Renew Business Permit → /apply?type=renewal · Amendment Form →
  /apply?type=amendment · Other Requirements → /requests. Logout flyout from avatar (p10):
  dark panel with Settings / Profile / Log Out.
- **Notifications (p19)**: white rows: avatar circle, bold name + "has approved your … Application."
  + right date, chevron. Map from our notifications feed.
- **Drafts (p20)**: pills All/New Permit/Renewal/Amendment (active = white bg royal text
  outline, inactive royal bg white text), trash icon right; content on a `panel` blue rounded
  area: grid of white cards (icon, name, "Edited {date}", pencil).
- **Payment History (p21–22)**: white rows "Ref No.: {ref}" bold + "Paid: {date}" italic muted,
  right serif ₱ amount + chevron; expands to **Tax Order of Payment** card: serif "Reference No:",
  Description/Charge serif table, rows "Item to be paid ₱…", serif "Total Amount: ₱…".
- **Permit Tracking = /applications (p48–49)**: pills All/New Permit/Renewal/Amendment; white
  accordion row per application (business name bold 18) with right block button: orange
  "Pay Online" (→ pay flow) or green "Paid". Expanded: per-permit-type rows with LEFT status
  chip (orange "For Approval", red "Rejected", yellow "For Inspection", green "Approved") +
  permit name + right "Submitted: {date}" + message icon.
- **Status pages = /applications/:id (p50, 52, 54–55, 57–58)**: centered serif heading
  "Application Status" (or "Payment Status" pre-payment); white card w/ colored top bar
  (6px): orange Pending/For Approval (hourglass + optional "Deadline: {date}"), yellow For
  Inspection (magnifier + "Scheduled Date"), red Rejected (X) then a "Remarks" heading +
  white remark rows + royal pill "Re-apply", green Approved (check + blue chip
  "👁 Business Permit" + download icon → /permits/:id). Below card right-aligned:
  "Mr/Ms {officer} - {Office}" royal italic + avatar + message icons.
- **Profile (p14–16)**: heading; white card: big avatar, name, "Joined {date}", right
  "{n} businesses total" + briefcase. Then serif "Approved Businesses" + Sort/Filter; white
  accordion rows (business name, right "Permit Expiration: {date}", red when nearing);
  expanded → **royal rows** per permit (white text, eye + download icons) → permit view modal.
- **Settings (p11–13)**: two full-width royal bars "Edit Profile" / "Reset Password" with
  chevrons → modals (blue header; avatar + first/last name; or password pair with eye toggles).
- **Wizard restyle (p26–47)**: keep our steps/data but restyle: page heading like
  "Zoning - Selecting Business Location"; bottom bar with royal pill Next/Submit bottom-left and
  center **green progress bar** + "Part {n} of 8" label. Address step: left map (Leaflet,
  rounded, with pin) + right filled inputs/selects (p27). After address, show zoning result
  modal: green CONGRATULATIONS (conforming, p30). Form steps: white sheet with kicker
  "BUSINESS PERMIT & LICENSING OFFICE · PHASE 1" (royal, letterspaced, 11px), h1
  "Application for New Business Permit" (+ Renewal/Amendment variants p34/p45), "Form Ref:
  MCG-BPLO-FO-001 · v2.0" small muted; lettered section markers (royal square with A/B +
  bold label); filled inputs; radio/check chips as light-blue pills (p34). Top bar of the
  wizard sheet: clipboard icon + editable draft title (business name) + cloud "All Changes Saved"
  + "Clear All" (→ WARNING modal). Docs step (p36): dashed light-blue upload bars (upload icon,
  bold title, small subtitle) + DATA PRIVACY CONSENT bordered box w/ checkbox. Requirements/LGU
  step (p37): serif "LGU Section", grid of white cards ({Permit name} bold, office italic serif,
  Submit + Apply small royal buttons) → SUBMISSION upload modal (p39). Final part: serif
  "LGU Section", "All clearances are applied for" centered muted, Submit pill →
  CONFIRMATION modal → submitted.
- **Other Requirements = /requests (p23–25)**: inbox rows (avatar, bold sender, "Subject Title -"
  preview, right date) → letter view (white sheet: subject h1, sender + date, letter body,
  blue chip "👁 Tax Order of Payment"/attachments + download, royal pill Reply) → reply
  composer (white card, avatar, "Text here…", Send pill + camera/upload icons + trash).
  Back this with the notifications/messages we have; static believable content acceptable
  where API lacks a thread model.

## 5. Staff screens (BPLO/CHO/BFP + admin)

- **Application Verification = /queue (p61, p80)**: pills "For Approval" (active) /
  "For Inspection"; white rows: business name bold + submitted date italic muted, right block
  chip: orange "Pending Payment" / green "Paid" (from application status). Row → review.
- **Review = /queue/:id (p56, p67–76)**: white sheet styled like the submitted form:
  kicker "BUSINESS PERMIT & LICENSING OFFICE · ADMIN REVIEW", h1, "Application No. {id}",
  lettered sections with **filled read-only inputs** of the real data; documents rows w/
  View/Download; green "Data Privacy Consent — agreed by applicant" note; tan/cream
  "FOR OFFICE USE ONLY — COMPLETE DURING REVIEW" box (#fdf6e3 bg, #e0c98f border): Date of
  Receipt, Received by, BAN, PSIC code chip, Assessed Fee (Php), Evaluator Remarks. Top-right
  header: red "Reject" + green "Approve" buttons (p68); after decision show status label
  top-right (green "Approved"/red underlined "Rejected"). Reject → remark popup (officer name,
  "Type here…", Cancel/Confirm p70); remarks appear as floating white bubbles right (p56).
  Approve/Reject drive our real assignment endpoints; then VERIFICATION modal
  "Where would you like to go?" → Home/Tracking (p83).
- **Inspections (p79, 81–82)**: list rows like queue; detail = status card For Inspection
  (yellow bar, scheduled date + calendar icon); when conducting: red remarks-doc button +
  green "Approve" (p81); fail → REMARKS FOR REJECTION red modal (complaint/description rows,
  green Add+) wired to our conduct endpoint (fail w/ findings), Approve → pass.
- **Analytics (p84–86)**: h1 + royal "Generate Report" pill top-right + slider icon; 4 white
  KPI cards (royal 30px number + muted label): Active Businesses, Yearly Applications,
  Monthly Applications, Compliance Rate — map to our summary (active_permits, totals,
  approval_rate); line chart card (applications by month), bar chart card (by type/status,
  multicolor bars like p84: blue/purple/orange/yellow); **Malabon map card**: Leaflet map of
  Malabon with green/red circle markers from business addresses (green active, red = expiring/
  flagged), click → business-name tooltip (p86).
- **Staff notifications (p91)**: same row style, "has submitted a business permit application."

## 6. Super Admin (p93–101)

- **Officer Assignment = /admin/users (p93)**: rail = Officer Assignment + Business Owner
  Status. h1 + search filled input + Sort/Filter. White table card: OFFICER (avatar initials
  circle + name) / OFFICE / STATUS chip (green Active, yellow On Leave, gray Inactive) /
  ACTIONS: royal pill "Reassign", small "Edit", "Deactivate". Footer "Showing 7 of N officers"
  + pagination. Modals: **Details** (p95: header w/ avatar, TOTAL ACTIONS / LAST ACTIVE /
  ACCOUNT STATUS row, then a dot timeline of audit entries — use real audit-logs),
  **Reassigning** (p96: Scope select, Reassign to select, Reason textarea),
  **Editing** (p97: surname/given/email/office/status/permit types), deactivate WARNING (p98).
  Wire Edit/Deactivate to our admin users endpoints; Reassign can be visual-only.
- **Business Owner Status = /admin/owners (p99–101)**: table: BUSINESS bold / OWNER / STATUS
  chip (red-tint Blacklisted, green Active, yellow Flagged, purple Suspended) / ACTIONS: red
  pill "Change Status" + "View Status History". **Changing Status modal** (p100: New status
  select, Reason code select, Details textarea, Effective/Review dates, Supporting document) →
  wire to owner toggle-active where it maps; **Status History modal** (p101: dot timeline,
  bold status + date + "who — reason"). Use businesses list + audit logs; fabricate reason
  copy where schema lacks it.

## 7. Public verify — keep route, restyle: canvas bg, white shadow card, serif accents, green
   valid banner; matches prototype card language.

## 8. Ground rules

- Real API stays wired (`VITE_USE_MOCK_API=false`); where the prototype shows data our API
  lacks (e.g. reassignment, chatbot, complaint threads), build the UI faithfully with graceful
  static/stub content — clearly non-crashing.
- The wizard keeps its working submit flow; visual restyle + "Part n of 8" framing.
- Kill the old flat-border/no-shadow look entirely: shadows, pills, filled inputs everywhere.
- Every screen must be compared against its prototype page PNG before being called done.
