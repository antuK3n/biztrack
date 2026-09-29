<?php

namespace App\Services\KwikPay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The three KwikPay endpoints BizTrack calls: /api/transfer (open a deposit),
 * /api/query (what became of it) and /api/me (is the connection good).
 *
 * Deposits only. No /api/daifu, no USDT — the LGU collects fees; it does not
 * pay anybody out through this. [Ken, 2026-09-29]
 *
 * ── What callers get back ───────────────────────────────────────────────────
 *
 * A KwikPayResult: the HTTP status, the decoded body, and whether the request
 * timed out or never connected. It never throws on a KwikPay error, because the
 * callers have to treat "KwikPay said no" and "KwikPay did not answer"
 * differently — the first is a failed payment, the second is an unknown one
 * (docs FAQ: "No answer is not no payment").
 *
 * ── The key ─────────────────────────────────────────────────────────────────
 *
 * Read from config at the moment of signing and never stored on the result,
 * put in an exception message, or logged. Every request is signed over exactly
 * the fields sent.
 */
class KwikPayClient
{
    /** @param  array<string, string>  $fields  without merchant or sign */
    public function transfer(array $fields): KwikPayResult
    {
        return $this->post('/api/transfer', $fields);
    }

    public function query(string $orderId): KwikPayResult
    {
        return $this->post('/api/query', ['order_id' => $orderId]);
    }

    public function me(): KwikPayResult
    {
        return $this->post('/api/me', []);
    }

    /** @param  array<string, string>  $fields */
    private function post(string $path, array $fields): KwikPayResult
    {
        $fields = ['merchant' => (string) config('payments.kwikpay.merchant')] + $fields;
        $fields['sign'] = Signature::make($fields, (string) config('payments.kwikpay.key'));

        $url = rtrim((string) config('payments.kwikpay.base_url'), '/').$path;

        try {
            /** @var Response $response */
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(max(1, (int) config('payments.kwikpay.timeout', 15)))
                ->connectTimeout(10)
                ->post($url, $fields);
        } catch (ConnectionException $e) {
            // Timed out or never connected. The message names the host, never
            // the body, so the key cannot ride along into a log from here.
            return KwikPayResult::noAnswer($e->getMessage());
        }

        $body = $response->json();

        return new KwikPayResult(
            httpStatus: $response->status(),
            body: is_array($body) ? $body : [],
        );
    }
}
