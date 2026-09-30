<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KwikPay\KwikPayCallback;
use App\Services\KwikPay\KwikPayGateway;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where KwikPay tells us a deposit's final result. Public: no sign-in, no CSRF
 * (it is an API route), and the signature is the authentication.
 *
 * ── The contract (merchant docs FAQ, "Callbacks and signing") ──────────────
 *
 * - Answer 2xx within 5 seconds. The body is logged by KwikPay and never read.
 * - A non-2xx is NOT retried; only a dropped connection is (3 tries, 3 s apart).
 * - Callbacks arrive as multipart/form-data, not JSON. This takes form bodies
 *   and JSON alike, so a support-triggered resend in either shape works.
 *
 * ── What it answers ─────────────────────────────────────────────────────────
 *
 * 200 "OK" for every callback that verifies, whatever was then done with it —
 * settled, a duplicate, an order we do not know, an amount we will not credit.
 * Those are ours to sort out (logged, audited, flagged to staff), and a
 * non-2xx would change nothing on KwikPay's side.
 *
 * 403 for a callback whose signature does not verify (or whose address is not
 * allowlisted, when an allowlist is set). It is either not from KwikPay or
 * signed with a key we do not hold, and KwikPay's own delivery log is where
 * someone will look first when a key is wrong — a 403 there says so; a 200
 * would hide it. Nothing retries it either way.
 *
 * The work is quick — one conditional UPDATE and the same status move a
 * simulated payment makes — so it is done before answering, not after. Should
 * it ever fail, the reconciliation command settles the payment from
 * /api/query instead.
 */
class KwikPayCallbackController extends Controller
{
    public function __invoke(Request $request, KwikPayCallback $callback): Response
    {
        /*
         * The fields exactly as received. `$request->request` is the form body
         * (multipart or urlencoded); a JSON body lands in `json()`. Query-string
         * parameters are deliberately not included — KwikPay signs the body.
         * TrimStrings and ConvertEmptyStringsToNull are switched off for this
         * route in bootstrap/app.php, because the signature is over the raw
         * values and `remark` may legitimately be "".
         */
        $fields = $request->isJson()
            ? (array) $request->json()->all()
            : $request->request->all();

        $ip = $request->ip();

        try {
            $outcome = $callback->handle($fields, $ip);
        } catch (\Throwable $e) {
            /*
             * Still a 2xx: the signature may have been fine and the failure
             * ours. KwikPay would not retry a 500 anyway, and reconciliation
             * will ask /api/query about the order.
             */
            report($e);
            KwikPayGateway::log('callback.error', null, ['ip' => $ip, 'fields' => $fields, 'error' => $e->getMessage()]);

            return response('OK', 200);
        }

        // The raw fields, all of them — KwikPay support asks for exactly this.
        // There is nothing secret in a callback: the key is never sent.
        KwikPayGateway::log('callback', null, ['ip' => $ip, 'outcome' => $outcome, 'fields' => $fields]);

        if (in_array($outcome, [KwikPayCallback::BAD_SIGNATURE, KwikPayCallback::IP_REFUSED], true)) {
            Audit::log('payment.callback_refused', null, [
                'reason' => $outcome,
                'order_id' => (string) ($fields['order_id'] ?? ''),
            ]);

            return response('Forbidden', 403);
        }

        if (in_array($outcome, [KwikPayCallback::UNKNOWN_ORDER, KwikPayCallback::WRONG_MERCHANT], true)) {
            Audit::log('payment.callback_unmatched', null, [
                'reason' => $outcome,
                'order_id' => (string) ($fields['order_id'] ?? ''),
            ]);
        }

        return response('OK', 200);
    }
}
