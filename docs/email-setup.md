# Email to business owners — setup

BizTrack e-mails a business owner every time it tells them something in the app:
submitted, returned, approved by BPLO, bill ready, paid, each clearance decision,
inspection booked / failed / passed, a requirement requested, rejected, permit
issued, expiry reminders, account suspended or restored. The code is finished.
**The only thing missing is a Brevo account and the `.env` lines in section 2.**

Until those lines are in, e-mails are written to `api/storage/logs/laravel.log`
instead of being sent (`MAIL_MAILER=log`). Nothing breaks either way.

**Setting a real mailer also switches on three account rules** (section 7): a
six-digit code by e-mail at every sign-in, an address confirmed by code at
sign-up, and a code by e-mail before a password change in Settings. Read
section 7 before changing `MAIL_MAILER`.

SMS is separate, and only for key moments: see `SMS_DRIVER` in `docs/runbook-deploy.md` §1.

---

## How it works (one paragraph)

`NotificationService::push()` writes each in-app notice. When the person being
told holds the `business_owner` role, it also queues one `SendOwnerUpdateEmail`
job, carrying the notice's title and text, the filing's tracking ID (or permit
number), the business name and a link into the owner's site built from
`FRONTEND_URL`. A **queue worker** picks the job up and sends it through the
configured mailer. The job tries 3 times (1 minute, then 5 minutes apart). If
the mail server is down, the officer's action still goes through. The failure
is only logged. Staff do not get these e-mails. The sign-in, confirmation and
password-change codes (section 7) are separate e-mails, sent straight away
rather than queued.

Code: `api/app/Jobs/SendOwnerUpdateEmail.php`, `api/app/Mail/OwnerUpdate.php`,
`api/resources/views/mail/owner-update*.blade.php`,
tests in `api/tests/Feature/OwnerUpdateEmailTest.php`.

---

## 1. Brevo account (about 15 minutes)

1. **Create the account** at <https://www.brevo.com> → *Sign up free*. Use an
   address the team can keep, not a personal one. Brevo asks for a phone number
   and a short company profile. Answer "transactional e-mail" and "City
   Government / LGU". The free plan is enough.
2. **Verify a sender.** Top-right menu → *Senders, Domains & Dedicated IPs* →
   *Senders* → *Add a sender*. Enter the From name (`BizTrack – City of Malabon`)
   and the address the e-mails will come from. Brevo sends a code to that
   address; enter it. **Only a verified sender can be used as
   `MAIL_FROM_ADDRESS`.** Brevo refuses anything else.
   - Use an address on a domain the City controls (for example
     `no-reply@malabon.gov.ph`) if MISD can add the DNS records Brevo shows under
     *Domains* (DKIM and DMARC). Without them, Gmail and Yahoo put the mail in
     spam or reject it.
   - A `@gmail.com` or `@yahoo.com` sender works for a demo, but those providers'
     DMARC rules mean many recipients will not receive it. Do not go live on one.
3. **Generate the SMTP key.** Top-right menu → *SMTP & API* → *SMTP* tab →
   *Generate a new SMTP key*. Name it `biztrack`. Copy the key when it appears;
   Brevo shows it only once. On the same page, copy the **Login**. It looks like
   `xxxxxxx@smtp-brevo.com` and is **not** your account e-mail. Server and port
   are shown there too: `smtp-relay.brevo.com`, `587`.

## 2. The lines to put in `api/.env`

Replace the existing `MAIL_*` lines (and `FRONTEND_URL` if present):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<the Login from Brevo's SMTP page, e.g. 8a1b2c001@smtp-brevo.com>
MAIL_PASSWORD=<the SMTP key you generated>
MAIL_FROM_ADDRESS=<the sender you verified in step 2>
MAIL_FROM_NAME="BizTrack – City of Malabon"
FRONTEND_URL=<where owners open BizTrack, e.g. https://biztrack.malabon.gov.ph>
QUEUE_CONNECTION=database
```

Notes:

- Leave `MAIL_SCHEME` **unset**. On port 587 Laravel connects in plain SMTP and
  upgrades to TLS with STARTTLS automatically. `MAIL_SCHEME=tls` is **not** a
  valid value on this Laravel version and will fail. (Port 465 would need
  `MAIL_SCHEME=smtps`; 587 is what Brevo recommends.)
- `config/mail.php` needs no change. Its `smtp` mailer reads exactly these
  variables.
- `FRONTEND_URL` is the button in every e-mail and the verify link printed on
  permits. For the tester tunnel, set it to the tunnel's URL. Left at the default
  (`http://localhost:5173`), the button only works on the developer's own machine.
- Production uses `api/.env.production` (see `infra/docker-compose.prod.yml`).
  Put the same lines there.
- After editing, run `php artisan config:clear` if the config was cached, and
  restart the worker (`php artisan queue:restart`). A running worker keeps the
  settings it started with.

## 3. The queue worker. Nothing sends without it.

E-mails are queued. If no worker is running they wait in the `jobs` table. The
owner still sees the in-app notice but gets no e-mail, and nothing reports an
error.

| Where | Worker |
|---|---|
| Production (`infra/docker-compose.prod.yml`) | the `queue` container already runs `php artisan queue:work` |
| Tester demo (`scripts/demo-up.sh`, `scripts/demo-deploy.sh`) | started by the script, log in `$TMPDIR/biztrack-demo/queue.log`, stopped by `demo-down.sh` |
| Local development (`./dev.sh`, `php artisan serve`) | **none**. Run it yourself in another terminal: |

```bash
cd api
php artisan queue:work           # keeps running; Ctrl+C to stop
php artisan queue:work --once    # process one job and exit
php artisan queue:failed         # jobs that ran out of retries
php artisan queue:retry all      # send those again
```

Waiting e-mails: `sqlite3 api/database/database.sqlite "select count(*) from jobs"`.

## 4. Send a test e-mail

```bash
cd api
php artisan biztrack:mail-test you@example.com
```

This sends one e-mail straight through the configured mailer (no queue, so no
worker needed) using the real template, and prints `Sent to …` or
`Not sent: <the relay's error>`. It exits 1 on failure. It warns when
`MAIL_FROM_ADDRESS` was never set, and when the mailer is `log` or `array`, which
deliver nothing.

Common failures:

| Message contains | Cause |
|---|---|
| `535` / `Authentication failed` | wrong `MAIL_USERNAME` (use the SMTP Login, not your account e-mail) or wrong key |
| `sender … not valid` / `not verified` | `MAIL_FROM_ADDRESS` is not a verified sender in Brevo |
| `Connection could not be established` | host/port wrong, or the network blocks outbound 587 (ask MISD) |

To test the whole path, **including the worker**, do something that notifies an
owner on a test filing (for example, send a message to its owner as BPLO), then
run `php artisan queue:work --once`. The owner's inbox should get it. With
`MAIL_MAILER=log` the full e-mail (text and HTML) appears at the end of
`storage/logs/laravel.log`.

## 5. The free-tier limit: 300 e-mails a day

Brevo's free plan sends **300 e-mails per day** across the account. One owner
update is one e-mail, so a busy day at the counter can pass it. Renewal season
is the risk: the nightly expiry scan (`biztrack:scan-permits`) sends a reminder
per permit at 30 / 15 / 7 / 1 days.

When the limit is reached, Brevo refuses further messages until the quota resets.
In BizTrack:

- the in-app notice is written as normal. Nobody loses information in the app.
- the e-mail job fails, retries after 1 and 5 minutes, and still fails. It then
  goes to `failed_jobs`, and `storage/logs/laravel.log` gets a warning
  `Owner update e-mail could not be sent`.
- those e-mails are **not** sent automatically later. After the quota resets,
  run `php artisan queue:retry all` to send them, or leave them. The owner
  already has the notice in the app.

If volume regularly passes 300, a paid Brevo plan (monthly e-mail volume, no
daily cap) changes only the account, not the code or the `.env` lines above.
The day's usage is shown in the Brevo dashboard.

## 6. Follow-ups (not built)

- **Owner opt-out.** Every update e-mails the owner. There is no setting to turn
  it off or reduce it to decisions only. It would be a per-user preference checked
  in `NotificationService::queueOwnerEmail()`. Not required for now.
- **Unverified addresses are e-mailed.** An owner who never confirmed their
  address still gets updates. With mail on they cannot file until they confirm
  (section 7), and any sign-in confirms the address, so this shrinks on its own;
  e-mailing only confirmed addresses is still not built.
- **"Trust this device for 30 days."** Not built. Every sign-in asks for a code.
- **Staff e-mail.** Officers get in-app notices only, by design (they are signed
  in all day). Revisit if an office asks for it.

## 7. Sign-in, address and password-change codes — on with the mailer

Three more e-mails, all a six-digit code, all built and all **off while
`MAIL_MAILER` is `log` or `array`**. The moment it is anything else (`smtp` for
Brevo), all three switch on together. The decision is made in one place,
`api/app/Support/EmailSwitch.php`; there is no separate flag.

| | Mail off (`log`, today) | Mail on (`smtp`) |
|---|---|---|
| Sign-in (all three doors) | password only | password, then a code e-mailed to the account |
| Sign-up | signed straight in; the old confirmation link is written to the log | a confirmation code is e-mailed, and the next screen asks for it; typing it confirms the address and signs the owner in |
| Filing (Submit on a draft) | allowed | refused for an unconfirmed address (a guard only: sign-up and sign-in already confirm it) |
| Profile | nothing extra | "Confirm your email address" box, for owners who have not |
| Change Password (Settings, every account) | current password only | current password, then a code e-mailed to the account |

**The sign-in code** (`Your BizTrack sign-in code`). Sent after a correct
password, for owners, officers and the super admin. Works for 10 minutes and 5
wrong tries. Wrong codes count toward the same 15-minute lockout as wrong
passwords. "Send a new code" works once a minute, up to 5 e-mails per sign-in.
Typing the code also confirms the address, so an owner who signs in never needs
the second e-mail.

**The confirmation code** (`Confirm your email address for BizTrack`). Sent on
sign-up, where it is asked on the sign-in page's code step before any session
is handed out (Ken, 6 October 2026: asked at sign-up, not on the application),
and from the "Send a new code" button on Profile. Works for 30 minutes and 5
wrong tries; only the newest one works. At sign-up, "Send a new code" works once
a minute, up to 5 e-mails, and wrong codes count toward the sign-in lockout;
from Profile the resend is limited to 3 in 15 minutes per account. An owner who
closes the tab before typing it is sent a sign-in code at their next sign-in,
whatever `AUTH_REQUIRE_VERIFIED_EMAIL` says (that switch now only matters with
mail off), and the code confirms the address the same way. Submit still refuses an unconfirmed
address, as a guard; resubmitting a filing an office sent back is not blocked.

**The password-change code** (`Your code to change your BizTrack password`).
Sent from Settings → Change Password → Send Code, only after the current
password is checked, so a signed-in session on its own cannot trigger one.
Works for 10 minutes and 5 wrong tries, like the sign-in code. Wrong codes
count toward the same 15-minute lockout as wrong passwords and wrong sign-in
codes; the lockout stops further codes and guesses but does not sign out the
session in use. "Send a new code" works once a minute, up to 5 e-mails per
code. A sign-in code is never accepted here, nor this code at sign-in. No
"your password was changed" e-mail is sent afterwards (not built; the change
already signs out every other device).

All three are **sent immediately, not queued**: somebody is waiting at the
screen that asked, and a queued code with no worker running would never arrive.
No worker is needed for these.

Codes are stored only as hashes (`email_codes` table). The code is not in the
subject line, so it does not show on a locked phone's notification.

Settings: `api/config/auth.php` → `email_codes` (expiry, tries, resend wait).
Code: `api/app/Support/EmailCodes.php`, `api/app/Mail/OneTimeCode.php`,
`api/resources/views/mail/one-time-code*.blade.php`, the sign-in and confirm
methods in `AuthController` (and `requestPasswordCode` / `updatePassword` for
the password code), `EnsureEmailConfirmedToFile` on the submit route,
`web/src/components/ChangePasswordModal.tsx`.
Tests: `api/tests/Feature/EmailCodeTest.php`,
`api/tests/Feature/PasswordChangeCodeTest.php`,
`web/e2e/password-change.spec.ts`.

**Before switching the mailer on:**

1. **Check the sender works** with `php artisan biztrack:mail-test you@example.com`
   (section 4). If the relay refuses, nobody can sign in: the sign-in page says
   the code could not be sent and does not fall back to the password. To get
   back in, set `MAIL_MAILER=log` again and run `php artisan config:clear`.
2. **Mind the 300-a-day limit** (section 5). Every sign-in is now one e-mail,
   on top of the owner updates. A busy day can use the quota up, and then
   nobody can sign in until it resets. A paid Brevo plan removes that limit.
3. **Existing owners are mostly unconfirmed.** Most accounts in the register
   have never confirmed their address (verification was a stub until item #61).
   They can still sign in, and signing in confirms them. Nobody is locked out,
   but an owner who registered and never signs in again cannot file until they
   do.
4. Run the migration that adds `email_codes`, one file at a time as AGENTS.md
   §2.2 asks:
   `php artisan migrate --path=database/migrations/2026_09_27_000100_email_codes_for_sign_in_and_address_confirmation.php --force`

---

## Captcha (Cloudflare Turnstile) — also only keys

Already built (`api/app/Support/Turnstile.php`,
`web/src/pages/auth/TurnstileWidget.tsx`). Nothing is missing but the two keys.
Checked on 24 September 2026 with Cloudflare's published always-pass test
keys: the widget loaded and produced a token, sign-in as an owner succeeded, the
API refused a sign-in with no token (422), and Cloudflare's always-fail secret
was refused.

1. Cloudflare dashboard → *Turnstile* → *Add widget*. Add the hostname(s) the
   site is served from, mode *Managed*.
2. Put the **secret key** in `api/.env`: `TURNSTILE_SECRET_KEY=<secret>`.
3. Put the **site key** in `web/.env` (or `web/.env.production`) as
   `VITE_TURNSTILE_SITE_KEY=<site key>`, **then rebuild the web app**. It is
   read at build time, so a running `vite preview` or a built `dist/` does not
   pick it up until `npm run build` runs again.

Set both or neither. With only the secret set, every sign-in fails because no
widget produces a token. With only the site key set, the widget shows but
nothing checks it.
