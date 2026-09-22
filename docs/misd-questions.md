# MISD — the deployment meeting

Outward-facing. This is what the team brings to the Management Information
Systems Department: the questions, in an order that works as a conversation,
and the facts about BizTrack that MISD will ask for in return.

The register of record is still `docs/questions-for-malabon.md`, section **B**
(AGENTS.md §8). Every question below that already has a register entry cites
its number; the ones that did not have one were added there as **B15–B26**, so
an answer gets written down once, beside what we assumed in the meantime.

Dated 22 September 2026. Numbers quoted (test counts, register sizes) are from
that day's `dev`.

---

## 0. What BizTrack is, in the words MISD needs

A conventional two-tier web application. Nothing exotic to host.

- **API** — Laravel 13 on PHP 8.4 (8.3 is the floor), a JSON REST API under
  `/api/v1`. Bearer tokens (Sanctum), one per portal. Roles and permissions in
  the database; office separation enforced server-side.
- **Web** — a React 18 / TypeScript single-page app, built by Vite into static
  files. Served by nginx, which proxies `/api` to PHP. No Node at runtime.
- **Database** — PostgreSQL 16 in the production runbook. SQLite in development.
  MySQL/MariaDB/SQL Server connections exist in config but are untested here.
- **Files** — uploaded requirements (≤10 MB each) and generated PDFs on the
  application's local storage disk. An S3-compatible store is a config change.
- **Background work** — a queue worker (email, notifications) and a scheduler:
  nightly permit-expiry scan, nightly analytics refresh (03:00), nightly backup
  (02:00). Queue, cache and sessions use the database — **no Redis needed**.
- **Three sign-in doors** — `/login` for business owners, `/staff/login` for
  BPLO and the five offices, `/admin/login` for the system administrator.

### What it needs from the network

| Direction | Host | Why | If blocked |
|---|---|---|---|
| in, from anywhere | the public hostname, 443 | owners file from home | owners cannot use it |
| in, City Hall only (optional) | `/staff/*`, `/admin/*` | officer and admin sites | — |
| out | `challenges.cloudflare.com` | sign-in captcha (Turnstile) | captcha is off; sign-in still works |
| out | `tile.openstreetmap.org` | street map on the zoning step | map is blank |
| out | `server.arcgisonline.com` | satellite view | satellite toggle dead |
| out | `nominatim.openstreetmap.org` | pin from the typed address | applicant pins by hand |
| out | the mail relay MISD names | verification and notification email | email goes to a log file |

Nothing else leaves the box. There is no LLM, no analytics vendor, no
telemetry. The chatbot is rule-based. Fonts are bundled.

### The shape as written (`infra/docker-compose.prod.yml`, `docs/runbook-deploy.md`)

`nginx:1.27` → `php-fpm` app + a `queue:work` container + a `schedule:work`
container + `postgres:16`. Environment from `api/.env.production`. Port 80;
TLS is expected from an LGU reverse proxy or certbot. PHP upload limits must be
raised to 12M/13M or a phone photo fails with a bare 413 (runbook §"PHP upload
limits").

What testers use today is **not** that: a Vite preview on a developer laptop
behind a Cloudflare quick tunnel (`scripts/demo-up.sh`). It is a demo path.

### What is simulated or switched off, on purpose, until MISD answers

| Thing | State today | Switch |
|---|---|---|
| Payment | simulated gateway, money never moves | `PAYMENT_DRIVER` — needs the real path (B14) |
| Captcha | inert, widget draws nothing | `TURNSTILE_SECRET_KEY`, `VITE_TURNSTILE_SITE_KEY` |
| Email | written to `storage/logs/laravel.log` | `MAIL_MAILER` + relay, `FRONTEND_URL` |
| Email verification at login | built, not enforced | `AUTH_REQUIRE_VERIFIED_EMAIL` (89 accounts unverified) |
| SMS | log driver | `SMS_DRIVER` — needs a gateway (B4) |
| Public holidays | not modelled in the RA 11032 clock | needs the City's calendar (B21) |
| Zoning verdict | a lookup, never a refusal | needs vector zoning data + CPDO's rule (C1–C5) |

---

## 1. The nineteen, grouped — with what sharpens each

Kept in the team's numbering. *Sharpen* is what to add so the answer is usable.

### Landscape

- **Q1** What do BPLO and the other offices use today, and where does it hurt?
  *Sharpen:* ask office by office — BPLO, CHO, BFP, OBO, CENRO, CPDO — and the
  **Treasurer's Office**, which is not a reviewer but issues the Official
  Receipt every filing ends on.
- **Q3** Would integration between offices be beneficial, and with what?
  *Sharpen:* name **eBPLS** and the Treasury system outright. Register **B1**.
- **Q9** One central database, or one per department?
- **Q11** Existing APIs or database connections BizTrack could use?
  *Sharpen:* ask for documentation, not a yes.

### Feasibility and the pilot

- **Q4** Is deployment feasible; if not city-wide, a pilot on BPLO or selected
  barangays?
- **Q16** What does a pilot need to be allowed into a real workflow?
- **Q17** MISD policies, standards, procurement or approval a student system must pass?
  *Sharpen for all three:* who signs off, what the parallel-run rule is, and
  what ends the pilot. Register **B24**, **B26**, **B13**.

### Sizing

- **Q5** Users, applications, transactions?
  *Sharpen:* the **January 1–20 renewal window** is the load, not the average.
  Ask for last January's count and the number of active businesses in the
  registry. Register **B18**.
- **Q19** Acceptable downtime and data loss?
  *Sharpen:* offer our figure first — BizTrack backs itself up nightly at
  02:00, so without something better the worst-case loss is a day — and ask
  whether that is acceptable for a pilot.

### The box

- **Q2** Compatibility, legacy software, OS versions, hardware limits?
  *Sharpen:* browser versions on officer PCs; whether **Docker** may run on the
  server; whether PHP 8.3+ and PostgreSQL 16 are acceptable. Register **B17**.
- **Q6** Server specs — OS, CPU, RAM, storage, installed database software?
  *Sharpen:* physical or virtual, and how much is spare.
- **Q7** Network — one LAN, VLANs, how connected?
  *Sharpen:* two things that decide the architecture — is the server
  **reachable from the internet** (owners file from home), and may the staff
  and admin sites be **restricted to City Hall's network**? And what the
  **outbound** policy is: four hosts need reaching, listed in §0. Register
  **B15**, **B16**.
- **Q8** Who administers day to day, and would we get access?
  *Sharpen:* a named counterpart and a channel. Register **B23**.
- **Q13** Backup procedure, schedule, storage, restore?
  *Sharpen:* also — where may BizTrack's **own** nightly backup write, and who
  runs a restore.

### Data

- **Q10** Existing data to migrate — format, structure, volume, quality?
  Register **B10**. *Sharpen:* ask for a sample export, however small.
- **Q12** Security and privacy requirements, RA 10173?
  *Sharpen:* the **Data Protection Officer's** name, whether the system must be
  **registered with the NPC**, and whether a Privacy Impact Assessment is
  expected before go-live. Register **B5**.
- **Q14** Does anything send verification email today, and through what?
  Register **B4**. *Sharpen:* SMTP relay details, or a sender they would license.
- **Q18** Digitised Revenue Code, zoning ordinance and maps; how are fees and
  zoning verified today; how often updated?
  *Sharpen:* ask specifically for **vector** zoning data (shapefile / GeoJSON).
  CPDO's sheets are raster images, which is why BizTrack cannot refuse a lot on
  zoning grounds. And the Revenue Code **edition and amendment cadence**.
  Register **C2–C4**, **A-series**.

### Legal

- **Q15** Are electronic signatures accepted, and of what kind?
  *Sharpen:* separately for the permit face, an office's approval, and the
  applicant's declaration (which CPDD's paper wants notarised). Register
  **B6**, **B26**.

---

## 2. What the nineteen do not cover

Each is a real dependency in the system. Register numbers are where the answer
and our interim assumption are kept.

### Money — the largest gap; the nineteen never mention it

- **How does a citizen pay, and who issues the Official Receipt?** Landbank
  LinkBiz, a PH aggregator (GCash, Maya), over the counter at the Treasurer, or
  several. Does the OR number come from Treasury's system? How is a
  BizTrack-issued Tax Order of Payment reconciled against it? **B14**.
- **Is there an e-payment ordinance?** Some LGUs need one before online
  collection is lawful.

### Identity

- **Is there a staff directory to sign in against?** Active Directory, LDAP,
  Google Workspace, an `@malabon.gov.ph` domain. Or are local accounts
  acceptable? **B11**.
- **Does every office have an official mailbox?** The client asked for accounts
  distinguished by office (`…@bfp`). Needs a domain to exist.
- **Who holds the super-administrator credential, and what is the break-glass
  procedure?** **B12**.
- **Is MFA or a password policy required by MISD?**

### National systems and verification

- **Is Malabon on eBPLS / eLGU, or expected to be?** Replace, coexist, or feed.
  **B1**.
- **What does the City report to ARTA under RA 11032, and in what format?**
  BizTrack already computes the 3/7/20 working-day tiers per filing; an
  export is cheap if the format is known. **B20**.
- **Is there any channel to verify DTI, SEC, CDA or BIR registration
  numbers?** Today the form can only warn that a number looks unusual, because
  no agency publishes a format. A lookup would settle it. **B19**.
- **Which PSIC edition does the City use** for line of business? **B9**.

### Hosting specifics

- **May the server reach the four external hosts** (captcha, map tiles,
  satellite, geocoding) and a mail relay? Each has a fallback; none is fatal.
  **B15**.
- **Should `/staff` and `/admin` be reachable only from City Hall?** Halves the
  attack surface at no cost to owners. **B16**.
- **Docker, or native?** The runbook is Docker Compose. Native means PHP 8.3+,
  nginx and PostgreSQL 16 installed by MISD. **B17**.
- **Who terminates TLS, and from what certificate?** Let's Encrypt allowed, or
  a gov.ph certificate?
- **Is DICT GovCloud an option** instead of the in-house box?
- **Are foreign-hosted dependencies acceptable** for a government system?
  The captcha is Cloudflare, the maps are OpenStreetMap and Esri, the tunnel
  today is Cloudflare. None receives citizen data beyond an IP address and a
  typed street name.

### Operations after handover

- **Who answers the citizen** when an upload fails, and how does a bug reach
  the team? Hours, escalation, a channel. **B23**.
- **What monitoring exists** — uptime, log collection — and should BizTrack's
  logs go somewhere?
- **Change control** — may the team deploy updates during the pilot, or does
  MISD control the window?
- **Where is the City's public-holiday calendar kept?** The RA 11032 clock
  counts working days and does not model holidays, so turnaround is currently
  overstated by every holiday. **B21**.
- **Who maintains it after the capstone, where does the repository live, and
  under what licence?** **B13**, **B26**.

### Data policy

- **Is the business registry public?** BizTrack has a public permit
  verification page (QR on every certificate) and an admin-only map of every
  business. FOI and RA 10173 pull opposite ways. **B22**.
- **Data residency** — must the data stay in-country? Relevant to any cloud
  option and to the external hosts above.
- **Retention** — how long are filings kept, and is there a records
  disposition schedule to follow? **B5**.

### At the counter and in the field

- **Do inspectors carry phones or tablets?** There is an Expo owner app in the
  repository; a field inspection app is a different thing and is not built.
  **B25**.
- **Printers for the permit face, scanners for assisted filing** of citizens
  without a device, a QR reader at the counter (a phone camera suffices).

### Document control

- **Are these the current form revisions?** BizTrack maps to MCG-BPLO-FO-003,
  MCG-CPDD-FO-003 v1.2 and MCG-CENRO-FO-001 v2.0 box for box. Who owns form
  revisions, and how would we hear of one?

### Pilot rules

- **Parallel run** — paper and BizTrack both, and which is authoritative when
  they disagree. **Duration. Exit criteria. Who signs off.** **B24**.
- **Which barangays or business types**, and how many live businesses is that.

---

## 3. What to bring, and what to ask for

**Bring**

- the tester URL and the three demo doors (`/login`, `/staff/login`, `/admin/login`)
- `docs/runbook-deploy.md` and `infra/docker-compose.prod.yml` — the box, as a diagram
- `docs/HANDOFF.md` §3, §11 — architecture and operations. **Note:** §3.2 still
  describes R as the analytics engine. R was removed on 28 August; analytics
  are computed in PHP into nightly snapshots. Say so before MISD reads it.
- this document, and `docs/questions-for-malabon.md` for the record

**Ask to leave with**

- the server sheet (Q6) and a network diagram (Q7)
- the DPO's name (Q12) and the holiday calendar (B21)
- a sample data export (Q10)
- the payment path, in writing (B14)
- a named counterpart and a channel (Q8)
