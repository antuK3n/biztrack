<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermitResource;
use App\Models\Permit;
use App\Support\QrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issued permits. Owners see their own (via business ownership); officers with
 * permit.view_all see all.
 */
class PermitController extends Controller
{
    private array $eager = ['permitType', 'business', 'application'];

    public function index(Request $request): JsonResponse
    {
        $query = Permit::with($this->eager);

        if (! $request->user()->hasPermission('permit.view_all')) {
            $query->whereHas('business', fn ($b) => $b->where('owner_user_id', $request->user()->id));
        }

        $permits = $query->orderByDesc('issued_at')->get();

        return response()->json(['data' => PermitResource::collection($permits)]);
    }

    public function show(Request $request, Permit $permit): JsonResponse
    {
        $permit->load($this->eager);
        $this->authorizeView($request, $permit);

        return response()->json(['data' => new PermitResource($permit)]);
    }

    /** dompdf permit certificate (CITY OF MALABON header, QR data-URI). */
    public function pdf(Request $request, Permit $permit): Response
    {
        $permit->load(['permitType.department', 'business.address.barangay', 'business.owner', 'application']);
        $this->authorizeView($request, $permit);

        $verifyUrl = rtrim((string) config('app.frontend_url'), '/').'/verify/'.$permit->permit_number;
        $b = $permit->business;

        $pdf = Pdf::loadView('pdf.permit', [
            'permit_number' => $permit->permit_number,
            'permit_type_name' => $permit->permitType?->name ?? 'Permit',
            'department_name' => $permit->permitType?->department?->name,
            'business_name' => $b?->name ?? '',
            'trade_name' => $b?->trade_name,
            'owner_name' => $b?->owner?->name ?? '',
            'address' => $b?->address?->line1 ?? '',
            'barangay' => $b?->address?->barangay?->name ?? '',
            'valid_from' => optional($permit->valid_from)->format('F j, Y'),
            'valid_until' => optional($permit->valid_until)->format('F j, Y'),
            'verify_url' => $verifyUrl,
            'qr' => QrCode::svgDataUri($verifyUrl),
        ]);

        $path = "private/permits/{$permit->id}.pdf";
        Storage::disk('local')->put($path, $pdf->output());
        if ($permit->pdf_path !== $path) {
            $permit->update(['pdf_path' => $path]);
        }

        return $pdf->download("permit-{$permit->permit_number}.pdf");
    }

    private function authorizeView(Request $request, Permit $permit): void
    {
        if ($request->user()->hasPermission('permit.view_all')) {
            return;
        }
        abort_unless(
            $permit->business && $permit->business->owner_user_id === $request->user()->id,
            403,
            'This permit is not yours.'
        );
    }
}
