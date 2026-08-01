<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermitResource;
use App\Models\Permit;
use App\Support\ApplicationVisibility;
use App\Support\PdfFile;
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
    private array $eager = ['permitType', 'business:id,name', 'application:id,tracking_id'];

    /**
     * Issued permits. Paginated, newest issuance first.
     *
     * Two things were wrong here. Unpaged it returned 4,122 rows and 1.7 MB —
     * every permit ever issued, on the request that renders "my permits".
     *
     * And it returned all 4,122 to *every office reviewer*, not just BPLO: the
     * gate was the bare `permit.view_all`, which the RBAC seeder grants to
     * sanitary, fire, zoning, OBO, CENRO and the market office alike. Measured
     * on the live register, a market administrator could list 2 applications and
     * 4,122 permits. `permit.view_all` gets the same reading `application.view_all`
     * already got in checklist item 56 — "filings other than my own, in the
     * offices I am routed to" — because the alternative is that the office
     * boundary holds on the filing and falls over on its outcome.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Permit::with($this->eager);
        $this->scopeToReader($request, $query);

        // issued_at is nullable on legacy rows; the id tiebreak keeps the page
        // boundary stable instead of letting equal keys shuffle between pages.
        $permits = $query->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => PermitResource::collection($permits->items()),
            'meta' => $this->pageMeta($permits),
        ]);
    }

    public function show(Request $request, Permit $permit): JsonResponse
    {
        $this->authorizeView($request, $permit);
        $permit->load($this->eager);

        return response()->json(['data' => new PermitResource($permit)]);
    }

    /** dompdf permit certificate (CITY OF MALABON header, QR data-URI). */
    public function pdf(Request $request, Permit $permit): Response
    {
        $this->authorizeView($request, $permit);
        $permit->load(['permitType.department', 'business.address.barangay', 'business.owner', 'application']);

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

        // Render once: a second ->output() corrupts the font streams (see PdfFile).
        $file = PdfFile::render($pdf);

        $path = "private/permits/{$permit->id}.pdf";
        Storage::disk('local')->put($path, $file->content);
        if ($permit->pdf_path !== $path) {
            $permit->update(['pdf_path' => $path]);
        }

        return $file->download("permit-{$permit->permit_number}.pdf");
    }

    /**
     * Narrow a permit query to what this reader may see.
     *
     * Owner: permits of businesses they own. BPLO / super admin: the register.
     * Every other office reviewer: permits issued off filings their office was
     * routed to — the same boundary ApplicationVisibility draws, reached through
     * the permit's application.
     */
    private function scopeToReader(Request $request, $query): void
    {
        $user = $request->user();

        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }

        if (! $user->hasPermission('permit.view_all')) {
            $query->whereHas('business', fn ($b) => $b->where('owner_user_id', $user->id));

            return;
        }

        $query->where(function ($sub) use ($user) {
            $sub->whereHas('business', fn ($b) => $b->where('owner_user_id', $user->id))
                ->orWhereHas('application', fn ($a) => ApplicationVisibility::scope($a, $user));
        });
    }

    /**
     * Read one permit. Same boundary as the list — a 403 on the list that a
     * direct id read walks around is not a boundary, and `/permits/{id}/pdf`
     * carries the owner's name and street address, which the public verify
     * endpoint deliberately does not.
     */
    private function authorizeView(Request $request, Permit $permit): void
    {
        $user = $request->user();

        if ($permit->business && $permit->business->owner_user_id === $user->id) {
            return;
        }
        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }

        $permit->loadMissing('application');
        $ok = $user->hasPermission('permit.view_all')
            && $permit->application
            && ApplicationVisibility::canView($user, $permit->application);

        abort_unless($ok, 403, 'This permit is not yours.');
    }
}
