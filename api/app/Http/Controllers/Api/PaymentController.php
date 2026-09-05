<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Application;
use App\Models\Payment;
use App\Services\PaymentGateway;
use App\Services\WorkflowService;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\PdfFile;
use App\Support\PermitFees;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fee display + simulated payment. Charge → WorkflowService::onPaymentCompleted
 * routes the application into review.
 */
class PaymentController extends Controller
{
    public function __construct(
        private PaymentGateway $gateway,
        private WorkflowService $workflow,
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

    public function pay(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $data = $request->validate([
            'method' => ['required', 'in:gcash,maya,card'],
        ]);

        /*
         * TWO payments are the flow, and this endpoint serves both
         * (docs/clearances-after-payment.md, "one ledger, two moments").
         *
         * The first settles the business permit's Tax Order of Payment and
         * moves the filing into review — and, the part not visible from here,
         * opens the LGU clearance stage. Every clearance applied for after that
         * re-assesses onto the same FeeAssessment, so a balance appears behind
         * a filing that is already under review, and settling it is the second
         * payment.
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
        $fee = $application->feeAssessment ?: $this->workflow->assessFees($application);
        $balanceDue = PermitFees::balance($application->fresh())['balance_due'];
        $awaitingFirstPayment = $application->status === ApplicationStatus::PendingPayment;

        $closed = in_array(
            $application->status,
            [ApplicationStatus::Rejected, ApplicationStatus::Cancelled],
            true
        );

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
         * Charge what is owed, never the assessment total. On the ordinary path
         * these are the same number — nothing has been paid yet, so the balance
         * IS the total — and where they differ, the total is money some of which
         * the applicant has already handed over.
         */
        $payment = $this->gateway->charge($fee, PaymentMethod::from($data['method']), $balanceDue);
        Audit::log('payment.completed', $payment, ['amount' => (string) $payment->amount]);

        $this->workflow->onPaymentCompleted($payment);

        return response()->json([
            'data' => new PaymentResource($payment->fresh()),
        ], 201);
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
            ->orderByDesc('paid_at')
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


    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
    }
}
