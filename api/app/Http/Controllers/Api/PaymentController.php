<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Application;
use App\Models\Payment;
use App\Models\PermitType;
use App\Services\FeeCalculator;
use App\Services\KwikPay\KwikPayGateway;
use App\Services\PaymentGateway;
use App\Services\WorkflowService;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\PaymentMode;
use App\Support\PdfFile;
use App\Support\PermitFees;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fee display + payment.
 *
 * Two ways to pay, chosen by App\Support\PaymentMode for each NEW payment
 * (docs/payment-gateway.md):
 *
 *   simulated  PaymentGateway::charge() completes at once and
 *              WorkflowService::onPaymentCompleted routes the application on,
 *              in this request — unchanged from before the switch existed.
 *   kwikpay    KwikPayGateway::open() leaves the payment PENDING and hands back
 *              where the owner pays. Nothing moves until KwikPay confirms it —
 *              by callback (KwikPayCallbackController) or by a query
 *              (`check` below, and the reconciliation command).
 */
class PaymentController extends Controller
{
    /** How long one payment holds its bill's lock at most — see `onePaymentAtATime`. */
    private const PAY_LOCK_SECONDS = 60;

    /** How long a second press waits for the first before giving up. */
    private const PAY_LOCK_WAIT_SECONDS = 30;

    public function __construct(
        private PaymentGateway $gateway,
        private WorkflowService $workflow,
        private KwikPayGateway $kwikpay,
    ) {}

    public function fee(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $fee = $application->feeAssessment;
        if (! $fee) {
            $fee = $this->workflow->assessFees($application);
        }

        return response()->json([
            'data' => [
                'line_items' => $fee->line_items,
                'total_amount' => $fee->total_amount,
            ],
        ]);
    }

    /**
     * What this filing would be billed, computed and thrown away.
     *
     * The wizard's fee step asked for a tax bracket, gross sales, a storey
     * count and a handful of flags, and showed the applicant NOTHING in return
     * — the amount appeared only after BPLO approved the form and the Tax Order
     * of Payment was raised. The client's question was the reasonable one: why
     * does this step exist? Data entry with no visible output invites exactly
     * that, so the step now shows what the answers produce.
     *
     * ── Nothing is persisted, deliberately ───────────────────────────────────
     *
     * `GET /fee` above cannot serve this: it calls `assessFees`, which does
     * `FeeAssessment::updateOrCreate`. Polling that while somebody types would
     * write an assessment row per keystroke-debounce and leave a draft carrying
     * a Tax Order of Payment nobody raised — and the real bill is supposed to
     * be raised once, at submission, over a final permit set. `FeeCalculator`
     * itself is pure, so this sets the inputs in memory, assesses, and returns.
     *
     * The profile comes from the REQUEST rather than the draft so the figure
     * tracks what is on screen instead of lagging a step behind the autosave.
     * It is not validated field by field: nothing is stored, the calculator
     * coerces what it reads, and the same profile is validated properly by
     * `ApplicationController::update` when it is actually saved.
     */
    public function feePreview(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $request->validate(['fee_profile' => ['sometimes', 'array']]);

        $application->loadMissing('business.lines');

        /*
         * In memory only — no save() anywhere in this method. `fee_profile` is
         * a cast attribute, so assigning it is enough for the calculator to
         * read it.
         */
        if ($request->has('fee_profile')) {
            $application->fee_profile = $request->input('fee_profile');
        }

        /*
         * The set submission WILL attach, not the one the draft holds. A new
         * filing carries only the business permit while the wizard is open, so
         * assessing over that would quote one permit and omit the five
         * clearances the applicant is about to pay for. Shared with
         * `attachRequiredPermitTypes` so the estimate and the bill cannot
         * disagree about which permits are being priced.
         */
        $application->setRelation(
            'permitTypes',
            PermitType::whereIn('id', WorkflowService::permitTypeIdsAtSubmission($application))->get(),
        );

        $assessed = app(FeeCalculator::class)->assess($application);

        return response()->json([
            'data' => [
                'line_items' => $assessed['items'],
                'total_amount' => $assessed['total'],
            ],
        ]);
    }

    public function pay(Request $request, Application $application): JsonResponse
    {
        return $this->onePaymentAtATime($application, fn (Application $fresh) => $this->payNow($request, $fresh));
    }

    /** `pay()`, once it holds the bill's lock — see `onePaymentAtATime`. */
    private function payNow(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        /*
         * The methods on offer are the current mode's. Card exists only while
         * payments are simulated; QR Ph and GoTyme only through KwikPay.
         */
        $mode = PaymentMode::current();
        $data = $request->validate([
            'method' => ['required', Rule::in(array_map(
                fn (PaymentMethod $m) => $m->value,
                PaymentMethod::forMode($mode),
            ))],
        ]);

        /*
         * ONE payment is the flow, and this endpoint may still be called twice.
         *
         * It used to say the opposite, citing docs/clearances-after-payment.md
         * and "one ledger, two moments": the first payment settled the business
         * permit and opened the clearance stage, and each clearance applied for
         * afterwards re-assessed onto the same FeeAssessment, so a balance
         * accrued behind a filing already under review and settling it was the
         * second payment. That document is superseded
         * (docs/application-flow-2026-09.md) and the accrual is gone with it —
         * `WorkflowService::submit()` attaches every required permit and THEN
         * assesses, so the single Tax Order of Payment prices all five up
         * front, and `ClearanceService::reassess()` no longer exists.
         *
         * A second call is still possible and must stay possible: an officer
         * can raise an assessment after payment. It is the exception now rather
         * than the design.
         *
         * This is why the endpoint is NOT restricted to `pending_payment`, and
         * the restriction must not come back. The last build of this design had
         * it, and the result was a balance the applicant could see, that the
         * release gate in WorkflowService::approveAndIssue was withholding their
         * permit over, and that no screen in the product could pay. Refusing
         * money the system itself says is owed is the failure mode worth
         * avoiding; an officer adjusting an assessment upward after payment
         * (WorkflowService::adjustFee) produces the same shape.
         *
         * `$awaitingFirstPayment` is what distinguishes them, and it has to be
         * a status test rather than a balance test: on the first payment the
         * balance and the assessment total are the same number, so a
         * balance-only rule could not tell "nothing has been paid yet" from
         * "everything has".
         *
         * A CLOSED filing is still refused, and closed here means rejected or
         * cancelled — not `isTerminal()`, which also covers Approved. That
         * distinction is load bearing: a clearance may be applied for on an
         * approved filing (ClearanceService::isUnlocked says so, and says why),
         * which raises a balance on a filing `isTerminal()` calls closed. The
         * applicant would then owe money the endpoint refused to take, which is
         * precisely the unpayable-balance bug named above wearing a different
         * status. There is genuinely nothing to buy on a rejection.
         */
        $closed = in_array(
            $application->status,
            [ApplicationStatus::Rejected, ApplicationStatus::Cancelled],
            true
        );

        /*
         * The LOWER bound, and it is checked before anything is assessed.
         *
         * Everything above concerns payments that arrive LATE — after review,
         * after an officer raises the assessment — and none of it says a word
         * about one arriving EARLY, because until 6 September 2026 none could:
         * submission moved a filing straight to PendingPayment, so there was no
         * status between the draft and the bill for a payment to land in.
         *
         * ForApproval is now exactly that status, and the wizard drove straight
         * into it — `submit()` then `pay()` in one press. Neither refusal below
         * fires there: the filing is not closed, and its balance is the whole
         * unpaid assessment. So the charge succeeded, and
         * `WorkflowService::onPaymentCompleted` then returned early because the
         * status was not PendingPayment. Money taken, filing unmoved, and BPLO's
         * approval afterwards asking the applicant for a bill already settled.
         *
         * Ordered ahead of `assessFees` deliberately: that call CREATES a fee
         * assessment when none exists, so leaving it above this guard would
         * raise a Tax Order of Payment on a draft merely because someone posted
         * to this endpoint.
         *
         * The client's rule, twice stated: BPLO approves the form, and only then
         * does the applicant pay.
         */
        if (! $closed && ! $application->status->isBillable()) {
            throw ValidationException::withMessages([
                'status' => ['BPLO has not approved this application yet, so there is nothing to pay.'],
            ]);
        }

        $fee = $application->feeAssessment ?: $this->workflow->assessFees($application);
        $balanceDue = PermitFees::balance($application->fresh())['balance_due'];
        $awaitingFirstPayment = $application->status === ApplicationStatus::PendingPayment;

        if (! $awaitingFirstPayment && $closed) {
            throw ValidationException::withMessages([
                'status' => ['This application is closed, so there is nothing left to pay.'],
            ]);
        }

        if (! $awaitingFirstPayment && $balanceDue <= 0) {
            throw ValidationException::withMessages([
                'status' => ['This application has nothing outstanding.'],
            ]);
        }

        /*
         * An online payment already waiting on KwikPay, with somewhere to pay
         * it, is handed back rather than doubled. The owner may have paid it in
         * their app a minute ago; a second order is how somebody pays twice.
         * Checked whatever the mode is now: the switch decides how NEW payments
         * are made, and resuming this one is not a new payment.
         *
         * One without a `pay_url` does not block: the owner was never given
         * anywhere to pay it, so they cannot have. It stays pending for
         * reconciliation, and a new order is safe.
         */
        $inFlight = $this->inFlight($application);
        if ($inFlight) {
            return response()->json(['data' => new PaymentResource($inFlight)]);
        }

        /*
         * Charge what is owed, never the assessment total. On the ordinary path
         * these are the same number — nothing has been paid yet, so the balance
         * IS the total — and where they differ, the total is money some of which
         * the applicant has already handed over.
         */
        if ($mode === PaymentMode::KWIKPAY) {
            $payment = $this->kwikpay->open($fee, PaymentMethod::from($data['method']), $balanceDue);

            if ($payment->status === PaymentStatus::Failed) {
                throw ValidationException::withMessages([
                    'method' => ['The payment could not be started, and nothing was charged. Please try again, or choose another way to pay.'],
                ]);
            }

            if ($payment->pay_url === null) {
                /*
                 * No answer, or an answer with nowhere to pay. The order may
                 * exist at KwikPay, so it is not failed and not retried here;
                 * reconciliation settles it either way.
                 */
                throw ValidationException::withMessages([
                    'method' => ['The payment service did not answer in time. Nothing has been charged. Please try again in a few minutes.'],
                ]);
            }

            return response()->json(['data' => new PaymentResource($payment)], 201);
        }

        $payment = $this->gateway->charge($fee, PaymentMethod::from($data['method']), $balanceDue);
        Audit::log('payment.completed', $payment, ['amount' => (string) $payment->amount]);

        $this->workflow->onPaymentCompleted($payment);

        return response()->json([
            'data' => new PaymentResource($payment->fresh()),
        ], 201);
    }

    /**
     * BPLO marks a bill paid over the counter at City Hall, for an owner who
     * paid in person instead of online [Ken, 4 Oct 2026]: "they should just be
     * able to find a user whose filing is pending to be paid and mark it, no
     * need to enter anything". Nothing is taken from the request — the amount
     * is the balance due, same as the owner's own `pay()`.
     *
     * Only BPLO. `application.review`, which the route sits behind, is held by
     * all seven offices (RbacSeeder) — BPLO is who raises and chases the Tax
     * Order of Payment, so `authorizeBploStaff` narrows it the same way
     * `AssignmentController::authorizeDepartment` narrows an office's other
     * review actions to its own cases, except the office here is always BPLO.
     *
     * The guards are `FilingMover::pay()`'s — the Debug page's own simulated
     * "pay the bill" step, which already has to answer the same two questions
     * (is this filing billable, is anything actually owed) with the same
     * refusals. One difference from both `FilingMover::pay()` and the owner's
     * own `pay()`: an online KwikPay payment still waiting on this filing is
     * set aside here rather than refused — see the note above that branch.
     */
    public function counterPayment(Request $request, Application $application): JsonResponse
    {
        return $this->onePaymentAtATime($application, fn (Application $fresh) => $this->counterPaymentNow($request, $fresh));
    }

    /** `counterPayment()`, once it holds the bill's lock. */
    private function counterPaymentNow(Request $request, Application $application): JsonResponse
    {
        $this->authorizeBploStaff($request);

        /*
         * Not for a business the super admin has suspended or blacklisted:
         * marking it paid released an Active Business Permit to a barred
         * business (scenario run, bplo-counter-payment 17). Refused before
         * anything is assessed or recorded.
         */
        $this->workflow->refuseWhileOnHold($application->business);

        $closed = in_array(
            $application->status,
            [ApplicationStatus::Rejected, ApplicationStatus::Cancelled],
            true
        );

        if (! $closed && ! $application->status->isBillable()) {
            throw ValidationException::withMessages([
                'status' => ['BPLO has not approved this application yet, so there is nothing to pay.'],
            ]);
        }

        $fee = $application->feeAssessment ?: $this->workflow->assessFees($application);
        $balanceDue = PermitFees::balance($application->fresh())['balance_due'];
        $awaitingFirstPayment = $application->status === ApplicationStatus::PendingPayment;

        if (! $awaitingFirstPayment && $closed) {
            throw ValidationException::withMessages([
                'status' => ['This application is closed, so there is nothing left to pay.'],
            ]);
        }

        if (! $awaitingFirstPayment && $balanceDue <= 0) {
            throw ValidationException::withMessages([
                'status' => ['This application has nothing outstanding.'],
            ]);
        }

        /*
         * An online KwikPay payment still waiting on this filing is set aside
         * — never refused, and never asked about first.
         *
         * `PaymentController::pay()` hands a waiting payment back to the owner
         * rather than opening a second, and `FilingMover::pay()` refuses
         * outright, because in both of those there is still somebody who might
         * go finish paying it. At the counter the owner is standing in front of
         * a BPLO clerk settling the bill right now; that online payment is not
         * going to be finished, so the clerk is not made to explain a KwikPay
         * order to the applicant before the counter payment can be taken.
         *
         * KwikPay is deliberately NOT asked first, unlike the owner's own "pay
         * a different way" (`KwikPayGateway::abandon()`): that call asks
         * because the OWNER is choosing to set the payment aside and might
         * have just paid it. Here BPLO is acting on the owner's behalf on a
         * payment the owner never opened from this seat, and there is no
         * reason to make the counter wait on a third party for it. If KwikPay
         * later reports it paid anyway, the callback / reconciliation `check()`
         * still completes it as usual, and `catchDoublePayment()` flags both
         * payments for a refund review — exactly as it would if the owner had
         * abandoned it themselves.
         */
        $waiting = $this->inFlight($application);
        if ($waiting !== null) {
            $waiting->update(['abandoned_at' => now()]);
            Audit::log('payment.abandoned', $waiting, [
                'gateway' => 'kwikpay',
                'order_id' => $waiting->gateway_order_id,
                'reason' => 'counter_payment',
            ]);
        }

        $payment = $this->gateway->charge($fee, PaymentMethod::Counter, $balanceDue);
        Audit::log('payment.completed', $payment, ['amount' => (string) $payment->amount, 'via' => 'counter']);

        $this->workflow->onPaymentCompleted($payment);

        return response()->json([
            'data' => new PaymentResource($payment->fresh()),
        ], 201);
    }

    /**
     * What the pay screen needs before the owner chooses: which mode, which
     * methods, and whether a payment is already waiting on KwikPay. The screen
     * reads the methods from here rather than listing them itself, because the
     * list depends on the mode and the mode can change on a running server.
     *
     * `test_charge` is the amount KwikPay will collect when the super admin's
     * charge switch says `test` and payments are online ("1.00"), and null
     * otherwise. The screen says so before the owner pays, so somebody
     * watching a ₱1 payment go through for a ₱2,000 bill sees that it is
     * deliberate. Only that one figure: the switch itself, the env and the
     * rest of PaymentMode::status() stay behind the super admin's endpoint,
     * and this route is the owner's own application only (authorizeOwner).
     */
    public function options(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $mode = PaymentMode::current();
        $inFlight = $this->inFlight($application);

        return response()->json([
            'data' => [
                'mode' => $mode,
                'methods' => array_map(fn (PaymentMethod $m) => [
                    'value' => $m->value,
                    'label' => $m->label(),
                ], PaymentMethod::forMode($mode)),
                'in_progress' => $inFlight ? new PaymentResource($inFlight) : null,
                'test_charge' => PaymentMode::ownerTestCharge(),
            ],
        ]);
    }

    /** One of the caller's own payments — what the waiting screen polls. */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePaymentOwner($request, $payment);

        return response()->json(['data' => new PaymentResource($payment)]);
    }

    /**
     * "Check payment status": ask KwikPay once, now, about this payment.
     *
     * The owner's way to hurry a confirmation along when they have paid and the
     * callback has not arrived. Throttled at the route. A payment that is not a
     * pending KwikPay one is returned as it is, without asking anybody.
     */
    public function check(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePaymentOwner($request, $payment);

        $payment = $this->kwikpay->check($payment, 'owner_check');

        return response()->json(['data' => new PaymentResource($payment->fresh())]);
    }

    /**
     * "Pay a different way": set a pending online payment aside so a new one
     * can be opened. KwikPayGateway::abandon asks KwikPay once first — the
     * answer may be that it was paid after all, in which case the payment
     * comes back completed and there is nothing to set aside.
     *
     * At most three per application per hour. Each one can end in a new order
     * at KwikPay, and an endless loop of set-aside orders is both noise in
     * their system and, if any of them is quietly paid, money to refund.
     */
    public function abandon(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePaymentOwner($request, $payment);

        if (! $payment->isKwikPay() || ! $payment->isPending() || $payment->abandoned_at !== null) {
            return response()->json(['data' => new PaymentResource($payment)]);
        }

        $key = 'payment-abandon:'.$payment->application_id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([
                'payment' => ["You have changed how you pay several times in the last hour. Please wait {$minutes} minute(s), or finish the payment you started."],
            ]);
        }
        RateLimiter::hit($key, 3600);

        return response()->json(['data' => new PaymentResource($this->kwikpay->abandon($payment))]);
    }

    /** The caller's payment history, most recent first. Paginated. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $payments = Payment::whereHas('application', fn ($q) => $q->where('applicant_user_id', $request->user()->id))
            ->with('application:id,tracking_id')
            // A payment not yet paid (paid_at null) after the paid ones, as it
            // always sorted on SQLite; PostgreSQL would put it first unless told.
            ->orderByRaw('paid_at desc nulls last')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => PaymentResource::collection($payments->items()),
            'meta' => $this->pageMeta($payments),
        ]);
    }

    /** dompdf receipt. The watermark is gone; the header and footer carry the simulated-payment disclosure. */
    public function receipt(Request $request, Payment $payment): Response
    {
        $payment->load(['application.business', 'application.feeAssessment']);
        $app = $payment->application;

        // The owner, or an officer whose office may open the filing this
        // receipt belongs to (checklist item 56).
        abort_unless(
            $app && ApplicationVisibility::canView($request->user(), $app),
            403,
            'This receipt is not yours.'
        );

        /*
         * A receipt says money was received. A pending or failed online payment
         * has not been, and a PDF saying otherwise is a document somebody could
         * wave at a counter. Simulated payments are all completed, so this
         * refuses nothing that used to work.
         */
        abort_unless(
            $payment->status === PaymentStatus::Completed,
            409,
            'There is no receipt for this payment because it has not gone through.'
        );

        $fee = $app?->feeAssessment;
        $items = $fee?->line_items ?? [['label' => 'Permit fees', 'amount' => $payment->amount]];

        $pdf = Pdf::loadView('pdf.receipt', [
            'reference_number' => $payment->reference_number,
            'tracking_id' => $app?->tracking_id ?? '',
            'business_name' => $app?->business?->name ?? '',
            'method' => $payment->method?->value ?? '',
            'paid_at' => optional($payment->paid_at)->format('F j, Y g:i A'),
            'line_items' => array_map(fn (array $item) => [
                'label' => self::receiptLabel($item['label'] ?? 'Fee'),
                'amount' => $item['amount'] ?? 0,
            ], $items),
            'total_amount' => $payment->amount,
        ]);

        // Render once: a second ->output() corrupts the font streams (see PdfFile).
        $file = PdfFile::render($pdf);

        $path = "private/receipts/{$payment->id}.pdf";
        Storage::disk('local')->put($path, $file->content);
        if ($payment->receipt_path !== $path) {
            $payment->update(['receipt_path' => $path]);
        }

        return $file->download("receipt-{$payment->reference_number}.pdf");
    }

    /**
     * Receipt-only label tidy-up (tester item 58). The ordinance wording in
     * database/data/revenue_code/*.json carries a parenthetical that explains
     * which schedule a rate came from — useful in the fee breakdown, noise on a
     * receipt. Only a bracket that follows a space is dropped, so codes written
     * inline (for example "Schedule S(5)") survive.
     */
    private static function receiptLabel(string $label): string
    {
        $stripped = preg_replace('/\s+\([^()]*\)/u', '', $label) ?? $label;

        return trim(preg_replace('/\s{2,}/u', ' ', $stripped) ?? $stripped);
    }

    /**
     * One payment at a time per bill (Ken, 5 October 2026).
     *
     * Both doors read the balance and then charged, with nothing held between
     * the two, so a second press inside that window passed the same check: the
     * owner's double tap, two clerks on two screens, or the owner paying
     * online while BPLO marked the bill paid each recorded a second payment of
     * the whole bill or opened a second live KwikPay order (scenario run,
     * owner-pay 2; bplo-counter-payment 7, 8, 12).
     *
     * So the owner's Pay and BPLO's Mark as paid take the same lock on the
     * filing, and everything they decide is decided again INSIDE it, from a
     * fresh read: a press that waited finds the bill settled and is refused,
     * or finds the online order the first one opened and is handed that.
     *
     * A cache lock rather than `lockForUpdate`. Opening a KwikPay order is an
     * HTTP call of up to `payments.kwikpay.timeout` seconds, and holding a
     * database transaction across it would also roll back the record of an
     * order KwikPay may already hold if anything failed after. The lock
     * expires on its own after `PAY_LOCK_SECONDS` — longer than a KwikPay
     * transfer can take (15 s to answer, 10 s to connect) — so a request that
     * dies holding it cannot block the bill for longer than that.
     */
    private function onePaymentAtATime(Application $application, Closure $then): JsonResponse
    {
        return Cache::lock('payment:application:'.$application->id, self::PAY_LOCK_SECONDS)
            ->block(self::PAY_LOCK_WAIT_SECONDS, fn () => $then(Application::findOrFail($application->id)));
    }

    /** A KwikPay payment for this application still waiting, with somewhere to pay it. */
    private function inFlight(Application $application): ?Payment
    {
        return Payment::query()
            ->awaitingKwikPay()
            ->where('application_id', $application->id)
            ->whereNotNull('pay_url')
            // Set aside by the owner: still reconciled, no longer blocking.
            ->whereNull('abandoned_at')
            ->latest('id')
            ->first();
    }

    private function authorizePaymentOwner(Request $request, Payment $payment): void
    {
        abort_unless(
            $payment->application?->applicant_user_id === $request->user()->id,
            403,
            'This payment is not yours.'
        );
    }

    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
    }

    /**
     * BPLO alone, among the seven offices `application.review` is shared with.
     *
     * A department check rather than a permission, because no permission says
     * "BPLO specifically" — every office holds the same `application.review`
     * this route sits behind. `department_id === null` (the super admin) is
     * refused too: Ken's decision names BPLO staff, not every reader who can
     * see every office's queue.
     */
    private function authorizeBploStaff(Request $request): void
    {
        $departmentId = $request->user()->department_id;

        abort_unless(
            $departmentId !== null && $departmentId === $this->workflow->bploDepartmentId(),
            403,
            'Only BPLO can mark a payment made at the counter.'
        );
    }
}
