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
use App\Support\Audit;
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

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::whereHas('application', fn ($q) => $q->where('applicant_user_id', $request->user()->id))
            ->orderByDesc('paid_at')
            ->get();

        return response()->json(['data' => PaymentResource::collection($payments)]);
    }

    /** dompdf receipt with a diagonal "SIMULATED PAYMENT" watermark. */
    public function receipt(Request $request, Payment $payment): Response
    {
        $payment->load(['application.business', 'application.feeAssessment']);
        $app = $payment->application;

        $isOwner = $app && $app->applicant_user_id === $request->user()->id;
        $isOfficer = $request->user()->hasPermission('application.view_all');
        abort_unless($isOwner || $isOfficer, 403, 'This receipt is not yours.');

        $fee = $app?->feeAssessment;

        $pdf = Pdf::loadView('pdf.receipt', [
            'reference_number' => $payment->reference_number,
            'tracking_id' => $app?->tracking_id ?? '',
            'business_name' => $app?->business?->name ?? '',
            'method' => $payment->method?->value ?? '',
            'paid_at' => optional($payment->paid_at)->format('F j, Y g:i A'),
            'line_items' => $fee?->line_items ?? [['label' => 'Permit fees', 'amount' => $payment->amount]],
            'total_amount' => $payment->amount,
        ]);

        $path = "private/receipts/{$payment->id}.pdf";
        Storage::disk('local')->put($path, $pdf->output());
        if ($payment->receipt_path !== $path) {
            $payment->update(['receipt_path' => $path]);
        }

        return $pdf->download("receipt-{$payment->reference_number}.pdf");
    }

    /** Officer adjusts the fee (permission fee.adjust on the route). */
    public function adjustFee(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.label' => ['required', 'string', 'max:255'],
            'line_items.*.amount' => ['required', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
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
