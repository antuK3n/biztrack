<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Application;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PermitType;
use App\Models\User;
use App\Services\FeeCalculator;
use App\Services\KwikPay\KwikPayGateway;
use App\Services\PaymentGateway;
use App\Services\WorkflowService;
use App\Support\AnnualInspection;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\DownloadLog;
use App\Support\Numbering;
use App\Support\OtherRequirementRules;
use App\Support\PaymentMode;
use App\Support\PdfFile;
use App\Support\PermitFees;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // The yearly inspection charges nothing; raising a bill here would
        // write a Tax Order of Payment for it (App\Support\AnnualInspection).
        abort_if(AnnualInspection::is($application), 404, 'The yearly inspection has no fee.');

        $fee = $application->feeAssessment;
        if (! $fee) {
            $fee = $this->workflow->assessFees($application);
        }

        return response()->json([
            'data' => [
                // Same shape as ApplicationResource's fee_assessment, number included.
                'reference_number' => Numbering::taxOrderReference($fee),
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
         * The set submission WILL attach, not the one the draft holds. Shared
         * with `attachRequiredPermitTypes` so the estimate and the bill cannot
         * disagree about which permits are being priced. Since 5 October 2026
         * that is the Business Permit alone on a new filing: BPLO picks its
         * other permits at approval and their fees join the bill then (client:
         * *"BPLO decides, no rules"*), which the wizard says beside the figure.
         */
        $application->setRelation(
            'permitTypes',
            PermitType::whereIn('id', WorkflowService::permitTypeIdsAtSubmission($application))->get(),
        );

        $calculator = app(FeeCalculator::class);
        $assessed = $calculator->assess($application);

        return response()->json([
            'data' => [
                'line_items' => $assessed['items'],
                'total_amount' => $assessed['total'],
                /*
                 * What the business's nature commits the filing to beyond the
                 * fees — the Liquor Permit behind the liquor filing fee, the
                 * Health Certificates behind the sanitary one. Read off the
                 * profile the estimate was just priced from, so the two lists
                 * cannot describe different businesses. `WorkflowService::
                 * submit` raises the rows that ask for something.
                 */
                'other_requirements' => OtherRequirementRules::preview($calculator->facts($application)),
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
         * Not for a business the super admin has suspended: its filings are on
         * hold, so a payment taken now could not move it (Ken, 5 October 2026).
         * A suspension no longer bars the owner's account, which is what used
         * to keep them off this endpoint. Refused before anything is assessed,
         * recorded or sent to KwikPay, the same as BPLO's counter payment.
         */
        $this->workflow->refuseWhileOnHold($application->business);

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
     * paid in person instead of online. The amount is the balance due, same as
     * the owner's own `pay()`.
     *
     * ── What the officer enters, since the thesis panel ─────────────────────
     *
     * It took nothing from the request until the panel's revisions [Ken, 4 Oct
     * 2026: "mark it, no need to enter anything"]. The matrix now promises an
     * OTC payment record (item 6) and OR records (item 5), so the officer
     * enters the Official Receipt's number and the date it was paid — both
     * required, pre-filled on screen from the owner's proof when there is one
     * (PaymentProofController) — and may attach a photo of the receipt. The
     * payment records them and who recorded it; the owner's pending proof is
     * linked to it, and so is the officer's photo.
     *
     * ── Who may, since the thesis panel ─────────────────────────────────────
     *
     * It was any BPLO officer. Matrix item 1: "Restricted 'Mark as paid' to the
     * staff member assigned to the application", so only the officer holding
     * BPLO's assignment on this filing may — see `authorizeCaseHolder`. Every
     * use is audited with the officer, the time, the filing and the OR.
     *
     * ── Not before the owner's receipt ──────────────────────────────────────
     *
     * Ken, 3 November 2026: Mark as paid needs the owner's receipt first, on
     * screen and here. A filing with no pending proof — none sent, or only a
     * superseded or rejected one — is refused with "The owner has not sent
     * their receipt yet." The officer either marks it paid against the
     * receipt or rejects the receipt (PaymentProofController::reject), and
     * the owner sends another. Asked after the bill's own refusals, so a
     * filing with nothing to pay still says that.
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
        // Asked before the lock too, so nobody who may not mark it paid waits on the bill's lock.
        self::authorizeCaseHolder($request->user(), $application);

        return $this->onePaymentAtATime($application, fn (Application $fresh) => $this->counterPaymentNow($request, $fresh));
    }

    /** `counterPayment()`, once it holds the bill's lock. */
    private function counterPaymentNow(Request $request, Application $application): JsonResponse
    {
        self::authorizeCaseHolder($request->user(), $application);

        /*
         * Not for a business the super admin has suspended or blacklisted:
         * marking it paid released an Active Business Permit to a barred
         * business (scenario run, bplo-counter-payment 17). Refused before
         * anything is assessed or recorded.
         */
        $this->workflow->refuseWhileOnHold($application->business);

        $data = $request->validate([
            /*
             * One OR, one payment: an OR number already on another payment is
             * refused, whatever its case or the spaces around it. Not a unique
             * index — older payments carry no OR at all — and not asked of the
             * owner's proof, which only offers an OR; the officer decides here.
             */
            'or_number' => ['bail', 'required', 'string', 'max:30', function (string $attribute, string $value, Closure $fail) {
                if (Payment::withOrNumber($value)->exists()) {
                    $fail('This OR number is already recorded.');
                }
            }],
            'or_date' => ['required', 'date', 'before_or_equal:today'],
            // The officer's own photo of the receipt, optional; the files a requirement takes.
            'photo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'or_number.required' => 'Enter the OR number.',
            'or_date.required' => 'Enter the date paid.',
            'or_date.before_or_equal' => 'The date paid cannot be in the future.',
            'photo.max' => 'The file may not be larger than 10MB.',
            'photo.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ], PaymentProofController::ATTRIBUTES);

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

        // The owner's receipt first (Ken, 3 November 2026); see the note above.
        if (PaymentProof::pendingFor($application) === null) {
            throw self::noReceiptYet();
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

        /*
         * The officer's photo is kept before the transaction — a file is not
         * rolled back — and the payment, the proof it settles and its audit
         * row are written as one, so no payment exists without its record.
         */
        $photo = $request->file('photo');
        $stored = $photo instanceof UploadedFile ? PaymentProofController::storePhoto($photo, $application) : null;
        $officer = $request->user();

        $payment = DB::transaction(function () use ($application, $fee, $balanceDue, $data, $stored, $officer) {
            /*
             * The owner's proof, if they sent one, is the proof of THIS payment.
             * Read before the charge: a completed payment is the cut-off
             * `pendingFor` measures from, so afterwards it would find nothing.
             */
            $owners = PaymentProof::pendingFor($application);

            /*
             * Asked again inside the transaction: rejected between the check
             * above and here, there is no receipt to mark it paid against, and
             * nothing is recorded.
             */
            if ($owners === null) {
                throw self::noReceiptYet();
            }

            $payment = $this->gateway->charge($fee, PaymentMethod::Counter, $balanceDue, [
                'or_number' => trim((string) $data['or_number']),
                'or_date' => $data['or_date'],
                'recorded_by_user_id' => $officer->id,
            ]);

            $owners->update(['payment_id' => $payment->id]);

            $officers = $stored === null ? null : PaymentProof::create([
                'application_id' => $application->id,
                'fee_assessment_id' => $fee->id,
                'payment_id' => $payment->id,
                'submitted_by_user_id' => $officer->id,
                'or_number' => $payment->or_number,
                'or_date' => $payment->or_date,
                ...$stored,
                'submitted_at' => now(),
            ]);

            /*
             * One row per Mark as paid: the officer (the row's actor), when
             * (the row's own time), the filing and the OR (matrix item 1).
             */
            Audit::log('payment.completed', $payment, [
                'amount' => (string) $payment->amount,
                'via' => 'counter',
                'application_id' => $application->id,
                'tracking_id' => $application->tracking_id,
                'or_number' => $payment->or_number,
                'or_date' => $payment->or_date->toDateString(),
                'proof_ids' => array_values(array_filter([$owners->id, $officers?->id])),
            ]);

            return $payment;
        });

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
     * charge switch says `test` and payments are online ("50.00"), and null
     * otherwise. The screen says so before the owner pays, so somebody
     * watching a ₱50 payment go through for a ₱2,000 bill sees that it is
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

    /**
     * The payment's INVOICE as a PDF (resources/views/pdf/receipt.blade.php).
     *
     * Still served at /receipt and stored under receipts/ — renaming a route
     * and an archive folder would break nothing a reader sees and every link
     * already handed out. What changed (6 October 2026) is what the document
     * calls itself: BizTrack does not issue Official Receipts.
     */
    public function receipt(Request $request, Payment $payment): Response
    {
        $payment->load(['application.business', 'application.feeAssessment']);
        $app = $payment->application;

        // The owner, or an officer whose office may open the filing this
        // receipt belongs to (checklist item 56).
        abort_unless(
            $app && ApplicationVisibility::canView($request->user(), $app),
            403,
            'This invoice is not yours.'
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
            'There is no invoice for this payment because it has not gone through.'
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

        return DownloadLog::record($file->download("invoice-{$payment->reference_number}.pdf"), 'invoice', $payment);
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

    /** Mark as paid's refusal when no receipt is waiting (Ken, 3 November 2026). */
    private static function noReceiptYet(): ValidationException
    {
        return ValidationException::withMessages([
            'proof' => ['The owner has not sent their receipt yet.'],
        ]);
    }

    /**
     * The BPLO officer holding this filing, and nobody else (thesis panel,
     * matrix item 1: "Restricted 'Mark as paid' to the staff member assigned
     * to the application").
     *
     * It was BPLO as an office until then — a department check, because every
     * office holds the `application.review` the route sat behind. Holding is
     * the officer in charge of BPLO's own assignment on the filing, the same
     * row "Assign to Me" claims. So another BPLO officer is refused, and so is
     * an UNHELD filing — it is taken first, which is where this parts company
     * with `AssignmentController::authorizeHolder`, whose acting on an unheld
     * case claims it: a payment has to be traceable to a person who chose to
     * hold the case before money was recorded against it. The super admin, the
     * clearance offices and the owner hold no BPLO assignment and get the same
     * one sentence; the route is outside the review group so the super admin
     * hears it too rather than a bare "no permission".
     *
     * Public and static since BPLO can reject the owner's receipt (Ken, 3
     * November 2026): the same officer judges the receipt as marks the bill
     * paid, so PaymentProofController::reject asks this same question and
     * refuses with this same sentence.
     */
    public static function authorizeCaseHolder(User $user, Application $application): void
    {
        $bplo = app(WorkflowService::class)->bploDepartmentId();

        abort_unless(
            $bplo !== null
                && $user->department_id === $bplo
                && $user->hasPermission('application.review')
                && $application->bploAssignment()->where('officer_user_id', $user->id)->exists(),
            403,
            'Only the officer handling this application can mark it as paid.'
        );
    }
}
