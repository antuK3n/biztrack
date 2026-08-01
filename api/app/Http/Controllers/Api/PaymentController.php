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

        if ($application->status !== ApplicationStatus::PendingPayment) {
            throw ValidationException::withMessages([
                'status' => ['This application is not awaiting payment.'],
            ]);
        }

        $fee = $application->feeAssessment ?: $this->workflow->assessFees($application);

        $payment = $this->gateway->charge($fee, PaymentMethod::from($data['method']));
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

    /** dompdf receipt with a diagonal "SIMULATED PAYMENT" watermark. */
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

    /**
     * Officer adjusts the fee (permission fee.adjust on the route).
     *
     * The office boundary is checked here too. `fee.adjust` currently only ever
     * sits alongside `application.view_any_office` (BPLO, admin), so this closes
     * nothing today — but rewriting somebody's Tax Order of Payment is a
     * stronger act than reading their filing, and it should not be the one
     * officer action that skips the check the reads all make.
     *
     * The money rules match the fee-profile ceilings: line items land in
     * decimal(14,2) columns, so an unbounded `numeric` is a 500 waiting for
     * someone to paste a long number.
     */
    public function adjustFee(Request $request, Application $application): JsonResponse
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'This application belongs to another office.'
        );

        $data = $request->validate([
            'line_items' => ['required', 'array', 'min:1', 'max:200'],
            'line_items.*.label' => ['required', 'string', 'max:255'],
            'line_items.*.amount' => ['required', 'numeric', 'min:0', 'max:10000000000'],
            'total_amount' => ['required', 'numeric', 'min:0', 'max:10000000000'],
        ]);

        $sum = array_sum(array_column($data['line_items'], 'amount'));
        if (abs($sum - (float) $data['total_amount']) > 0.01) {
            throw ValidationException::withMessages([
                'total_amount' => ['The total must equal the sum of the line items.'],
            ]);
        }

        $fee = $this->workflow->adjustFee(
            $application,
            $data['line_items'],
            (float) $data['total_amount'],
            $request->user(),
        );

        return response()->json([
            'data' => [
                'line_items' => $fee->line_items,
                'total_amount' => $fee->total_amount,
            ],
        ]);
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
