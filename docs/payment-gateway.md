# Payment gateway: simulated or KwikPay

BizTrack takes a permit payment one of two ways. A switch picks which one
**new** payments use.

| Mode | What the owner sees | What moves money |
|---|---|---|
| `simulated` (default) | Chooses GCash, Maya or Card and presses **Pay Online**. The screen shows **Paid** straight away, with the line "This is a simulated payment. No real charge is made." | Nothing. This is how BizTrack has always behaved, and it is what the presentation runs on. |
| `kwikpay` | Chooses GCash, Maya, QR Ph or GoTyme. They are sent to the payment page, or shown a QR code to scan, and finish paying in their own app. The pay screen waits and shows **Paid** once the payment is confirmed. If the payment fails, it says so and offers **Try again**. | KwikPay (merchant docs: `merchant-api-docs-en.md`). Deposits only: no payouts, no USDT. |

A second switch decides **what KwikPay collects** for a new payment: a
**test charge** (₱1.00 unless `KWIKPAY_CHARGE_OVERRIDE` names another amount)
or **the full bill**. The bill, the payment record and the receipt always keep
the real assessed amount; only the figure sent to KwikPay changes. The thesis
defense runs on the test charge, and the super admin switches to the full bill
from the Debug page if a panelist asks to see the real amount.

**Neither switch touches a payment that already exists.** A KwikPay
payment opened while the switch was on is still confirmed after it is turned
off: its callback is still accepted and reconciliation still asks about it.
Each payment records the path that made it in `payments.gateway`, and what
KwikPay was asked to collect in `payments.gateway_amount`. A confirmation is
checked against that amount, so a ₱1.00 order still settles for ₱1.00 after
the charge is switched to the full bill, and a full-bill order still needs the
full bill after it is switched back.

---

## 1. Settings (api/.env)

All are listed in `api/.env.example` with empty values.

| Key | What it is | Default |
|---|---|---|
| `PAYMENT_GATEWAY` | The mode to use until someone flips the switch (`simulated` or `kwikpay`). After that, the saved switch is used. | `simulated` |
| `KWIKPAY_BASE_URL` | KwikPay's address. KwikPay's own Back Office is `https://pay4-kwikpay.jd.management`; the gateway our merchant account is on answers the same API. | `https://payment-gateway-kwgu.onrender.com` |
| `KWIKPAY_MERCHANT` | Merchant ID from KwikPay. Not a secret: it is sent in the clear with every request. | `harson-tech` |
| `KWIKPAY_KEY` | Merchant key from KwikPay. Signs every request and checks every callback. It is never sent to the browser, shown by the status API, or written to a log. | — |
| `KWIKPAY_PAYMENT_TYPE` | `"1"`–`"12"`. KwikPay decides which are enabled (see §5); harson-tech's account takes any of `"1"`–`"4"`. | `1` |
| `KWIKPAY_CALLBACK_BASE_URL` | The **public** address KwikPay calls back, without `/api/v1`. The callback is `<this>/api/v1/payments/kwikpay/callback`. | `APP_URL` |
| `KWIKPAY_CALLBACK_IPS` | Optional comma-separated allowlist for callbacks. KwikPay's docs give `34.21.238.122`. Leave it empty unless trusted proxies are set up (see §6). The signature is always checked either way. | empty (not enforced) |
| `KWIKPAY_TIMEOUT` | Seconds to wait for KwikPay. | `15` |
| `KWIKPAY_FAKE` | Turns on the practice KwikPay (§4). Only works when `APP_ENV` is `local` or `testing`. | `false` |
| `KWIKPAY_CHARGE_OVERRIDE` | Two jobs. It is the **test amount**: what KwikPay collects while the charge switch says "test charge" (₱1.00 when it is empty or not a positive number). And until somebody sets the charge switch, it is the **default**: a positive amount means the test charge, empty means the full bill, exactly as before the switch existed. Once the switch is set (Debug page, API or artisan), the switch decides. The bill, the payment record and the receipt keep the real assessed amount either way. | empty (full bill; test amount ₱1.00) |

The two switches themselves are not env settings. They live in the `settings`
table (`payment_gateway` and `kwikpay_charge`), so they change on a running
server; the env values above are only what applies until somebody switches.

So the one setting a server must be given is `KWIKPAY_KEY`. The server refuses to switch to KwikPay while
`KWIKPAY_MERCHANT`, `KWIKPAY_KEY` or `KWIKPAY_PAYMENT_TYPE` is missing. The refusal lists the missing keys by
name.

---

## 2. Switching

Three switches, three ways to reach them. Every change is written to the audit
log, with who made it:

| Door | Audit action | Recorded |
|---|---|---|
| Debug page (`/api/v1/debug/payments`) | `debug.payments` | all three switches `before` and `after`, actor |
| Admin API (`/api/v1/admin/payment-gateway`) | `payment_gateway.switched`, `payment_gateway.charge_switched` | `from`, `to`, `via: api`, actor (and `test_amount` for the charge) |
| Terminal (artisan) | the same two, plus `payment_gateway.confirm_switched` | `from`, `to`, `via: artisan`, no actor |

### From the Debug page (super admin)

The **Payments** section of the Debug page (`/admin/debug`, or just `/debug`)
shows the switches as pairs of cards:

- **How owners pay:** Simulated or KwikPay. When KwikPay cannot be turned on,
  the page lists the settings missing on the server. **Test connection**
  makes one signed call to KwikPay and shows the answer.
- **What KwikPay collects:** "₱1.00 test charge" or "The full bill". Switching
  to the full bill asks for confirmation first, because owners are then
  charged real money. Switching back to the test charge asks nothing.
- **What marks a payment paid:** "Only the signed confirmation" (the
  default) or "Its answer when asked, too". See below.
- **Online payments still waiting**, and the ones flagged for staff.

### What marks a payment paid

KwikPay can say a payment went through in two ways: its signed callback, or
its answer when BizTrack asks `/api/query`. The merchant docs allow both. The
default is the **signed callback only**: on 4 October 2026 the gateway at
`payment-gateway-kwgu.onrender.com` answered
`{"status":"5","message":"Transaction is waiting to be processed"}` for an
order whose payment page said it had expired unpaid, and BizTrack credited
₱8,150 nobody paid. With the default, `/api/query` is still asked (its message
is kept on the payment as a note) but its answer completes and fails nothing.
On a server KwikPay cannot reach, such as localhost, nothing then turns Paid.

A third choice, **its answer's message** (`read-message`), fits the gateway at
`payment-gateway-kwgu.onrender.com` as it actually behaves: its `/api/query`
answers `status "5"` whenever the lookup works, and the order's state is the
message — "Transaction completed successfully" (paid), "Transaction is waiting
to be processed" (waiting) or "Transaction failed" (failed). That is how the
gateway's own code reads KwikPay (`mapQueryState` in its kwikpay.processor.ts).
biztrack.page uses this since 4 October 2026.

biztrack.page runs its test charge at **₱50** (`KWIKPAY_CHARGE_OVERRIDE=50.00`
on the server): the processor did not register a ₱1 GCash payment on 4 October
2026, and ₱50 is known to work.

The Debug page is closed by default. It opens only from the server, for a
few hours at a time, and closes by itself after that:

```bash
cd api
php artisan biztrack:debug-panel on --hours=6   # 1 to 24 hours; 6 if not given
php artisan biztrack:debug-panel status
php artisan biztrack:debug-panel off
```

While it is closed, the Debug rail entry is hidden and every `/api/v1/debug`
route answers 404, the super admin included. With `APP_ENV=local` the flag is
not needed.

### From a terminal

```bash
cd api
php artisan biztrack:payment-gateway status       # mode, charge, config check, payments still waiting
php artisan biztrack:payment-gateway test         # one signed call to KwikPay /api/me
php artisan biztrack:payment-gateway on           # → kwikpay (refused if credentials are missing)
php artisan biztrack:payment-gateway off          # → simulated
php artisan biztrack:payment-gateway test-charge  # KwikPay collects the test amount (₱1.00)
php artisan biztrack:payment-gateway full-charge  # KwikPay collects the full bill
php artisan biztrack:payment-gateway callback-only  # only the signed callback marks a payment paid (default)
php artisan biztrack:payment-gateway trust-query  # KwikPay's /api/query answer settles payments too
php artisan biztrack:payment-gateway read-message  # the answer's MESSAGE settles payments (this gateway's way)
```

### Over the API (super admin only)

| | |
|---|---|
| `GET /api/v1/admin/payment-gateway` | Mode and charge (`test` or `full`), their defaults from env, the test amount, whether KwikPay is configured (missing keys by name), the callback URL KwikPay must reach, how many online payments are still waiting, and the ones flagged for staff. |
| `PUT /api/v1/admin/payment-gateway` `{"mode":"kwikpay"}`, `{"charge":"full"}` or both | Switches. At least one of the two is required, and a request with only `mode` works as it always has. Returns 422 with a plain message if credentials are missing; a request for both that is refused on the mode changes neither. |
| `POST /api/v1/admin/payment-gateway/test` | Calls KwikPay `/api/me` and reports whether KwikPay accepted this server's address and signature. |

Every other role gets 403. The Debug page's `GET/PUT /api/v1/debug/payments`
and `POST /api/v1/debug/payments/test` take the same bodies and answer the
same way, behind the Debug page's gate instead.

### What the owner sees

While payments go through KwikPay and the test charge is on, the pay screen
says before the owner pays: "Test charge. You will be charged ₱1.00 for this
bill instead of ₱3,596.00. The bill and your receipt keep the full amount."
The waiting screen shows the amount the payment was opened for, with "Test
charge. Your bill of ₱3,596.00 is recorded in full." The owner's API gets
only `test_charge` on `payment-options` and `gateway_amount` on their own
payments, never the switches themselves.

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
3. **Only two things can mark it paid** (KwikPay FAQ), and by default only the
   first (see "What marks a payment paid" above): a callback whose
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
   to ask, and every deposit is rejected without it. For harson-tech the
   answer is any of 1–4; the default is 1.
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

The defense takes real payments through KwikPay at ₱1. Run these on the
**server being presented** (for the tunnel, in `biztrack-demo/api`), because
both switches and the Debug page's flag are stored in that server's database.

- [ ] `php artisan biztrack:debug-panel on --hours=6` shortly before the
      defense, so the super admin has the Debug page. It closes by itself;
      `off` closes it sooner.
- [ ] On the Debug page, under **Payments**: **KwikPay** is on, and
      **₱1.00 test charge** is on. (Or from a terminal:
      `biztrack:payment-gateway on` and `biztrack:payment-gateway test-charge`,
      then `status` says **KwikPay collects: a ₱1.00 test charge**.)
- [ ] **Test connection** says "Connected".
- [ ] The owner's pay screen shows **GCash, Maya, QR Ph, GoTyme** and the
      "Test charge" note naming ₱1.00.
- [ ] "Online payments still waiting" is 0, or each waiting one is
      understood. Switching does not cancel them.
- [ ] `KWIKPAY_FAKE` is not set on the presented server unless the practice
      flow is being shown on purpose.

If a panelist asks to see the real amount: Debug page → **The full bill** →
**Charge the full bill**. The next payment collects the whole bill; one
already started still asks for ₱1.00. Switch back to **₱1.00 test charge**
afterwards.

To show the old no-money flow instead, switch **How owners pay** to
**Simulated**: the pay screen then shows **GCash, Maya, Card** and "This is a
simulated payment. No real charge is made.".

## 7. Where the code is

| | |
|---|---|
| Switches | `api/app/Support/PaymentMode.php` (mode and charge), `settings` table, `api/app/Console/Commands/PaymentGatewaySwitch.php`, `api/app/Http/Controllers/Api/Admin/PaymentGatewayController.php` |
| Debug page | `api/app/Support/DebugPanel.php` (who may open it), `api/app/Http/Middleware/EnsureDebugPanelOpen.php`, `api/routes/debug.php`, `api/app/Http/Controllers/Api/Debug/`, `api/app/Console/Commands/DebugPanelSwitch.php`, `web/src/pages/admin/debug/` |
| KwikPay | `api/app/Services/KwikPay/` (`Signature`, `KwikPayClient`, `KwikPayGateway`, `KwikPayCallback`) |
| Callback | `api/app/Http/Controllers/Api/KwikPayCallbackController.php` |
| Reconciliation | `api/app/Console/Commands/ReconcilePayments.php`, scheduled in `api/routes/console.php` |
| Practice KwikPay | `api/app/Http/Controllers/FakeKwikPayController.php`, `api/app/Services/KwikPay/FakeKwikPay.php` |
| Simulated | `api/app/Services/PaymentGateway.php` (unchanged behaviour) |
| Owner screen | `web/src/pages/applicant/PayPage.tsx` |
| Tests | `api/tests/Unit/KwikPaySignatureTest.php`, `api/tests/Feature/KwikPayGatewayTest.php`, `api/tests/Feature/FakeKwikPayTest.php`, `api/tests/Feature/DebugPanelTest.php`, `web/e2e/payment-gateway.spec.ts`, `web/e2e/debug-payments.spec.ts` |
