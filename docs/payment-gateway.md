# Payment gateway: simulated or KwikPay

BizTrack takes a permit payment one of two ways. A switch picks which one
**new** payments use.

| Mode | What the owner sees | What moves money |
|---|---|---|
| `simulated` (default) | Chooses GCash, Maya or Card and presses **Pay Online**. The screen shows **Paid** straight away, with the line "This is a simulated payment. No real charge is made." | Nothing. This is how BizTrack has always behaved, and it is what the presentation runs on. |
| `kwikpay` | Chooses GCash, Maya, QR Ph or GoTyme. They are sent to the payment page, or shown a QR code to scan, and finish paying in their own app. The pay screen waits and shows **Paid** once the payment is confirmed. If the payment fails, it says so and offers **Try again**. | KwikPay (merchant docs: `merchant-api-docs-en.md`). Deposits only: no payouts, no USDT. |

**The switch never touches a payment that already exists.** A KwikPay
payment opened while the switch was on is still confirmed after it is turned
off: its callback is still accepted and reconciliation still asks about it.
Each payment records the path that made it in `payments.gateway`.

---

## 1. Settings (api/.env)

All are listed in `api/.env.example` with empty values.

| Key | What it is | Default |
|---|---|---|
| `PAYMENT_GATEWAY` | The mode to use until someone flips the switch (`simulated` or `kwikpay`). After that, the saved switch is used. | `simulated` |
| `KWIKPAY_BASE_URL` | KwikPay's address. | `https://pay4-kwikpay.jd.management` |
| `KWIKPAY_MERCHANT` | Merchant number from KwikPay. | — |
| `KWIKPAY_KEY` | Merchant key from KwikPay. Signs every request and checks every callback. It is never sent to the browser, shown by the status API, or written to a log. | — |
| `KWIKPAY_PAYMENT_TYPE` | `"1"`–`"12"`. KwikPay decides which one (see §5). | — |
| `KWIKPAY_CALLBACK_BASE_URL` | The **public** address KwikPay calls back, without `/api/v1`. The callback is `<this>/api/v1/payments/kwikpay/callback`. | `APP_URL` |
| `KWIKPAY_CALLBACK_IPS` | Optional comma-separated allowlist for callbacks. KwikPay's docs give `34.21.238.122`. Leave it empty unless trusted proxies are set up (see §6). The signature is always checked either way. | empty (not enforced) |
| `KWIKPAY_TIMEOUT` | Seconds to wait for KwikPay. | `15` |
| `KWIKPAY_FAKE` | Turns on the practice KwikPay (§4). Only works when `APP_ENV` is `local` or `testing`. | `false` |

The server refuses to switch to KwikPay while `KWIKPAY_MERCHANT`, `KWIKPAY_KEY`
or `KWIKPAY_PAYMENT_TYPE` is missing. The refusal lists the missing keys by
name.

---

## 2. Switching

Every switch is written to the audit log (`payment_gateway.switched`, with
`from`, `to`, and `via` set to `api` or `artisan`).

### From a terminal

```bash
cd api
php artisan biztrack:payment-gateway status   # mode, config check, payments still waiting
php artisan biztrack:payment-gateway test     # one signed call to KwikPay /api/me
php artisan biztrack:payment-gateway on       # → kwikpay (refused if credentials are missing)
php artisan biztrack:payment-gateway off      # → simulated
```

### Over the API (super admin only)

A debug screen will be built on these later.

| | |
|---|---|
| `GET /api/v1/admin/payment-gateway` | Mode, default, whether KwikPay is configured (missing keys by name), the callback URL KwikPay must reach, how many online payments are still waiting, and the ones flagged for staff. |
| `PUT /api/v1/admin/payment-gateway` `{"mode":"kwikpay"}` | Switches. Returns 422 with a plain message if credentials are missing. |
| `POST /api/v1/admin/payment-gateway/test` | Calls KwikPay `/api/me` and reports whether KwikPay accepted this server's address and signature. |

Every other role gets 403.

---

## 3. How an online payment settles

1. **Pay** creates a `pending` payment and calls KwikPay `/api/transfer` for
   the balance due. The `order_id` is the `PAY-` reference plus a random
   suffix, because a reference number alone repeats across the live, demo and
   test databases, and KwikPay rejects a reused order id.
2. KwikPay answers with a payment page (`redirect_url`) or a QR code
   (`qrcode_url` / `gcash_qr_url`). The first one that is not empty is used.
   The owner is sent to the link or shown the QR code.
   - A clear refusal from KwikPay (a 4xx, or `status: "0"`) marks the payment
     `failed`. The owner is told nothing was charged and can try again.
   - A timeout or a 5xx leaves the payment `pending`, because the order might
     exist at KwikPay. It is never retried automatically, and reconciliation
     settles it. The owner can start a new payment, because they were never
     given anywhere to pay the first one.
3. **Only two things mark it paid** (KwikPay FAQ): a callback whose
   signature is valid and that carries `status 5`, or `"5"` from
   `/api/query`. The application moves on at that moment and not before.
4. **Callback** (`POST /api/v1/payments/kwikpay/callback`, public). It
   checks, in order: the IP allowlist if one is set; the signature over every
   field received, with `amount` hashed exactly as sent (`100.000000`); the
   merchant; that the order is one of ours; and the amount, compared as a
   number. Then `5` completes the payment and `3` fails it.
   - It answers **200** whenever the signature is valid, including for a
     duplicate, an unknown order or a wrong amount. Those cases are logged,
     audited and, for a wrong amount, flagged for staff.
   - It answers **403** when the signature is wrong. KwikPay does not retry
     non-2xx responses, and a 403 in their delivery log is the quickest sign
     that the key is wrong.
   - It takes multipart, form and JSON bodies.
5. **Reconciliation**: `biztrack:reconcile-payments` runs every minute from
   the scheduler, so the server needs `php artisan schedule:work` or a cron
   entry for `schedule:run`. It asks `/api/query` about each pending KwikPay
   payment older than 2 minutes, backing off (2, 4, 8, 16, 32 minutes, then
   hourly). `"5"` completes, `"3"` fails, and `"1"` or `"0"` leave the payment
   alone. After **24 hours** still pending, the payment is **flagged**: every
   super admin gets a notification and it appears under `flagged` in the
   status API. It is never failed automatically.
6. The owner's **Check payment status** button makes one query straight away.
   The pay screen also checks once on its own when the owner comes back from
   the payment page.

7. **Pay a different way.** While an online payment is waiting, the pay
   screen offers **Pay a different way**. It first asks for confirmation: "If
   you already paid with <method>, wait for it to be confirmed instead —
   paying again could charge you twice." After the owner confirms,
   `POST /api/v1/payments/{id}/abandon` asks `/api/query` once:
   - `"5"`: the payment is completed as usual and the owner sees **Paid**.
     No new payment is opened.
   - `"3"`: the payment is marked failed and the owner chooses again.
   - `"1"`, `"0"` or no answer: the payment is **set aside**
     (`abandoned_at`). It stays `pending`, is still reconciled, and its
     callback is still accepted. It just stops blocking a new order.

   If a set-aside payment later turns out to be paid, and another payment for
   the same application was also paid, the one that settled second is marked
   `refund_review_at`. Every super admin and BPLO officer is notified, an
   audit row is written (`payment.double_paid`), and the owner's payment
   history says "paid twice, BPLO will contact you about a refund". Nothing is
   refunded automatically. The limit is 3 set-asides per application per
   hour.

Completing a payment is a single conditional update (`… WHERE status =
'pending'`), and whichever path gets there first does the work. A second
callback, or a callback that arrives after reconciliation, does nothing.

Receipts are only issued for **completed** payments.

Every exchange with KwikPay, and every callback with its raw fields, is logged
to `storage/logs/payments-*.log` (kept 90 days). KwikPay support asks for
these when a payment is disputed.

---

## 4. Demo with the practice KwikPay

This works without credentials. The API serves a stand-in KwikPay with the
same endpoints, the same signature checks, and a callback sent the same way
(multipart, six-decimal amount, signed). It also serves a practice payment
page with **Pay** and **Fail** buttons in place of the owner's app. QR Ph gets
a QR code and every other channel gets a link, so both screens can be shown.

It is available only when `APP_ENV` is `local` or `testing` **and**
`KWIKPAY_FAKE=true`. This is checked when routes are registered and again on
every request, so it cannot run in production.

```bash
# api/ — any merchant/key values; the fake checks against these
APP_ENV=local KWIKPAY_FAKE=true \
KWIKPAY_MERCHANT=FAKE01 KWIKPAY_KEY=practice-key KWIKPAY_PAYMENT_TYPE=1 \
KWIKPAY_BASE_URL=http://localhost:8080/api/v1/fake-kwikpay \
KWIKPAY_CALLBACK_BASE_URL=http://localhost:8080 \
FRONTEND_URL=http://localhost:5173 \
PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8080 --no-reload

php artisan biztrack:payment-gateway on
```

`PHP_CLI_SERVER_WORKERS=4 … --no-reload` is required. With the fake, the API
calls itself: `/api/transfer` during Pay, and the callback when **Pay** is
pressed on the practice page. A single-worker `artisan serve` would wait
forever for its own reply.

The practice page is reached through the web app's own address
(`FRONTEND_URL` + `/api/v1/fake-kwikpay/…`, proxied by Vite), so it also works
behind the tunnel.

The e2e spec `web/e2e/payment-gateway.spec.ts` runs this flow end to end. It
skips the online half with a reason when the stack was not started with the
fake.

---

## 5. What KwikPay has to do on their side

Ask the KwikPay account manager for these before switching on:

1. **Merchant number and merchant key** (`KWIKPAY_MERCHANT`, `KWIKPAY_KEY`).
2. **Which `payment_type` to use** (`KWIKPAY_PAYMENT_TYPE`). Their docs say
   to ask, and every deposit is rejected without it.
3. **Allowlist our server's outbound IP address.** KwikPay checks the calling
   IP on every request (transfer, query and me), and anything else gets
   `403 IP not found in allowed IPs`. Send the address of the machine that
   runs the API, or the whole range if it runs on autoscaling infrastructure.
   `biztrack:payment-gateway test` shows whether it has been done.
4. **The callback URL must be publicly reachable.** KwikPay posts to
   `KWIKPAY_CALLBACK_BASE_URL/api/v1/payments/kwikpay/callback`, and
   `status` prints the exact address. The tester tunnel is a Cloudflare
   *quick* tunnel whose `trycloudflare.com` address changes on every
   redeploy, and each order carries the callback URL of the moment it was
   created. After a redeploy, callbacks for older orders go to a dead address.
   Reconciliation still settles those orders from `/api/query`, but slower
   (minutes, not seconds). For anything beyond a trial, use a fixed address:
   a named Cloudflare tunnel or the MISD server.
5. Things worth confirming with KwikPay:
   - whether the callback `amount` is the order amount or the amount after
     their fee (we credit only when it equals the order amount; otherwise the
     payment is flagged for staff);
   - whether `remark` is enabled on our account (either way works; we sign
     whatever arrives);
   - how long a channel can stay pending before we should chase it (we flag
     after 24 hours).

---

## 6. Presentation checklist

- [ ] `php artisan biztrack:payment-gateway status` says **New payments:
      simulated**. If it does not, run `php artisan biztrack:payment-gateway off`.
      Run this on the **server being presented** (for the tunnel,
      in `biztrack-demo/api`), because the switch is stored in that server's
      database.
- [ ] The pay screen shows **GCash, Maya, Card** and the line "This is a
      simulated payment. No real charge is made."
- [ ] "Online payments still waiting" is 0, or each waiting one is
      understood. Switching off does not cancel them.
- [ ] `KWIKPAY_FAKE` is not set on the presented server unless the practice
      flow is being shown on purpose.

## 7. Where the code is

| | |
|---|---|
| Switch | `api/app/Support/PaymentMode.php`, `settings` table, `api/app/Console/Commands/PaymentGatewaySwitch.php`, `api/app/Http/Controllers/Api/Admin/PaymentGatewayController.php` |
| KwikPay | `api/app/Services/KwikPay/` (`Signature`, `KwikPayClient`, `KwikPayGateway`, `KwikPayCallback`) |
| Callback | `api/app/Http/Controllers/Api/KwikPayCallbackController.php` |
| Reconciliation | `api/app/Console/Commands/ReconcilePayments.php`, scheduled in `api/routes/console.php` |
| Practice KwikPay | `api/app/Http/Controllers/FakeKwikPayController.php`, `api/app/Services/KwikPay/FakeKwikPay.php` |
| Simulated | `api/app/Services/PaymentGateway.php` (unchanged behaviour) |
| Owner screen | `web/src/pages/applicant/PayPage.tsx` |
| Tests | `api/tests/Unit/KwikPaySignatureTest.php`, `api/tests/Feature/KwikPayGatewayTest.php`, `api/tests/Feature/FakeKwikPayTest.php`, `web/e2e/payment-gateway.spec.ts` |
