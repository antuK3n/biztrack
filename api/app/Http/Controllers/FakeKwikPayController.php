<?php

namespace App\Http\Controllers;

use App\Services\KwikPay\FakeKwikPay;
use App\Services\KwikPay\Signature;
use App\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A stand-in KwikPay, so the whole online-payment flow can be demonstrated and
 * tested end to end without credentials (docs/payment-gateway.md, "Demo with
 * the fake").
 *
 * Mounted at /api/v1/fake-kwikpay ONLY when FakeKwikPay::available() — local or
 * testing, and KWIKPAY_FAKE=true — and every action re-checks that. Point
 * KWIKPAY_BASE_URL at http://<api>/api/v1/fake-kwikpay and set any
 * KWIKPAY_MERCHANT / KWIKPAY_KEY / KWIKPAY_PAYMENT_TYPE: the fake checks our
 * requests against those exactly as KwikPay would against the real ones.
 *
 * What it imitates, from the merchant docs:
 *   /api/transfer  signature, payment_type "1"–"12", known bank_code, unique
 *                  order_id (409 on reuse). QR Ph comes back as a `qrcode_url`,
 *                  every other channel as a `redirect_url` — so both of the
 *                  owner screen's paths are exercised.
 *   /api/query     "1" waiting, "3" failed, "5" paid; 404 + "0" when unknown.
 *   /api/me        merchant, display name, balances.
 *   callback       multipart/form-data, `amount` with six decimals, signed with
 *                  the merchant key over the fields sent.
 *
 * And a payment page with Pay and Fail buttons standing in for the owner's
 * GCash app. It is deliberately unstyled: SecurityHeaders sends
 * `default-src 'none'` on every API response, which is right for the API and
 * not worth loosening for a practice page. Orders are held in the cache for a
 * day.
 *
 * What it shows says nothing about practice or play money (Ken, 5 October
 * 2026, for a defense where BizTrack is presented as a working system): the
 * page is headed "Payment", and /api/me names the account "BizTrack". It read
 * "Practice payment", "No real money moves" and "Practice account (not real
 * money)" before.
 *
 * Not imitated: the IP allowlist, fees, USDT and withdrawals.
 */
class FakeKwikPayController extends Controller
{
    private const BANK_CODES = ['gcash', 'PMP', 'GOT', 'usdt-trc20', 'qrph'];

    public function transfer(Request $request): JsonResponse
    {
        $fields = $this->fields($request);
        if ($refusal = $this->refuse($fields)) {
            return $refusal;
        }

        $type = (string) ($fields['payment_type'] ?? '');
        if (! ctype_digit($type) || (int) $type < 1 || (int) $type > 12) {
            return $this->error(400, 'payment_type must be "1"–"12"');
        }
        if (! in_array($fields['bank_code'] ?? null, self::BANK_CODES, true)) {
            return $this->error(400, 'Unknown bank_code');
        }
        foreach (['amount', 'order_id', 'callback_url', 'return_url'] as $required) {
            if ((string) ($fields[$required] ?? '') === '') {
                return $this->error(400, "{$required} is required");
            }
        }

        $orderId = (string) $fields['order_id'];
        if (Cache::has($this->key($orderId))) {
            return $this->error(409, 'Duplicate order id');
        }

        Cache::put($this->key($orderId), [
            'order_id' => $orderId,
            'amount' => (string) $fields['amount'],
            'bank_code' => (string) $fields['bank_code'],
            'callback_url' => (string) $fields['callback_url'],
            'return_url' => (string) $fields['return_url'],
            'status' => '1',
        ], now()->addDay());

        $isQr = $fields['bank_code'] === 'qrph';

        return response()->json([
            'status' => '1',
            'message' => 'Transfer initiated successfully',
            'amount' => (float) $fields['amount'],
            'order_id' => $orderId,
            'redirect_url' => $isQr ? '' : $this->pageUrl($orderId),
            'qrcode_url' => $isQr ? $this->publicBase().'/qr/'.rawurlencode($orderId) : '',
            'gcash_qr_url' => '',
            'remark' => null,
        ]);
    }

    public function query(Request $request): JsonResponse
    {
        $fields = $this->fields($request);
        if ($refusal = $this->refuse($fields)) {
            return $refusal;
        }

        $order = Cache::get($this->key((string) ($fields['order_id'] ?? '')));
        if (! $order) {
            return $this->error(404, 'Order not found');
        }

        return response()->json([
            'status' => $order['status'],
            'message' => match ($order['status']) {
                '1' => 'Transaction is waiting to be processed',
                '3' => 'Transaction failed',
                '5' => 'Transaction completed successfully',
                default => 'Transaction status unknown',
            },
            'order_id' => $order['order_id'],
            'amount' => (float) $order['amount'],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $fields = $this->fields($request);
        if ($refusal = $this->refuse($fields)) {
            return $refusal;
        }

        return response()->json([
            'merchant' => (string) $fields['merchant'],
            'merchant_display_name' => 'BizTrack',
            'balance' => '0.0000',
            'pending_balance' => '0.0000',
            'sign' => (string) $fields['sign'],
        ]);
    }

    /** The stand-in for the owner's GCash / bank app. */
    public function page(string $orderId): Response
    {
        abort_unless(FakeKwikPay::available(), 404);
        $order = Cache::get($this->key($orderId));
        abort_unless($order, 404, 'No such order.');

        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $action = $this->publicBase().'/pay/'.rawurlencode($orderId);
        $state = match ($order['status']) {
            '5' => '<p class="done">This order has been paid.</p>',
            '3' => '<p class="done">This order failed.</p>',
            default => '<form method="post" action="'.$e($action).'/pay"><button class="pay">Pay ₱'.$e(number_format((float) $order['amount'], 2)).'</button></form>'
                .'<form method="post" action="'.$e($action).'/fail"><button class="fail">Fail this payment</button></form>',
        };

        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment</title></head><body><main>
<h1>Payment</h1>
<dl><dt>Order</dt><dd>{$e($orderId)}</dd><dt>Amount</dt><dd>₱{$e(number_format((float) $order['amount'], 2))}</dd><dt>Channel</dt><dd>{$e($order['bank_code'])}</dd></dl>
{$state}
</main></body></html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Pay or fail the order: send the signed callback, then go back to BizTrack. */
    public function settle(string $orderId, string $outcome): RedirectResponse
    {
        abort_unless(FakeKwikPay::available(), 404);
        abort_unless(in_array($outcome, ['pay', 'fail'], true), 404);
        $order = Cache::get($this->key($orderId));
        abort_unless($order, 404, 'No such order.');

        if ($order['status'] === '1') {
            $order['status'] = $outcome === 'pay' ? '5' : '3';
            Cache::put($this->key($orderId), $order, now()->addDay());
            $this->sendCallback($order);
        }

        return redirect()->away($order['return_url']);
    }

    /** The QR Ph code: an SVG that encodes the practice payment page. */
    public function qr(string $orderId): Response
    {
        abort_unless(FakeKwikPay::available(), 404);
        abort_unless(Cache::has($this->key($orderId)), 404);

        $uri = QrCode::svgDataUri($this->pageUrl($orderId));
        $svg = base64_decode((string) substr($uri, strlen('data:image/svg+xml;base64,')));

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * The deposit callback, as KwikPay sends it: multipart/form-data, amount
     * with six decimals, signed over exactly these fields. A failure to deliver
     * is logged and left — the real one retries only on a dropped connection,
     * and BizTrack's reconciliation is what covers the rest.
     *
     * @param  array<string, string>  $order
     */
    private function sendCallback(array $order): void
    {
        $fields = [
            'status' => $order['status'],
            'amount' => number_format((float) $order['amount'], 6, '.', ''),
            'message' => $order['status'] === '5' ? '成功' : '拒絶',
            'merchant' => (string) config('payments.kwikpay.merchant'),
            'order_id' => $order['order_id'],
            'callback_url' => $order['callback_url'],
        ];
        $fields['sign'] = Signature::make($fields, (string) config('payments.kwikpay.key'));

        try {
            Http::asMultipart()->timeout(5)->post($order['callback_url'], $fields);
        } catch (\Throwable $e) {
            Log::warning('fake-kwikpay: callback not delivered', ['order_id' => $order['order_id'], 'error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function fields(Request $request): array
    {
        return $request->isJson() ? (array) $request->json()->all() : $request->request->all();
    }

    /** The checks every real endpoint makes before anything else. */
    private function refuse(array $fields): ?JsonResponse
    {
        abort_unless(FakeKwikPay::available(), 404);

        if ((string) ($fields['merchant'] ?? '') !== (string) config('payments.kwikpay.merchant')) {
            return $this->error(400, 'Unknown merchant');
        }
        if (! Signature::verify($fields, (string) config('payments.kwikpay.key'))) {
            return $this->error(401, 'Signature verification failed');
        }

        return null;
    }

    private function error(int $http, string $message): JsonResponse
    {
        return response()->json(['status' => '0', 'message' => $message], $http);
    }

    private function key(string $orderId): string
    {
        return 'fake-kwikpay:order:'.$orderId;
    }

    /*
     * Links a BROWSER follows go through the web app's origin, which proxies
     * /api to this server (vite.config.ts) — so the practice page works behind
     * a tunnel, where this server's own address is unreachable.
     */
    private function publicBase(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/api/v1/fake-kwikpay';
    }

    private function pageUrl(string $orderId): string
    {
        return $this->publicBase().'/pay/'.rawurlencode($orderId);
    }
}
