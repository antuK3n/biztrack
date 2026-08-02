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

    /**
     * One permit, plus the certificate block.
     *
     * The screen that renders a permit is a picture of the paper certificate,
     * so it needs the same fields the PDF prints — owner, address, line of
     * business, signature block — and PermitResource carries none of them; it
     * is the list row shape, shared with five other screens that want it small.
     * Rather than widen that for one consumer, the extra fields ride alongside
     * it under their own key, the way AuthController::userPayload adds
     * `created_at` to UserResource.
     *
     * Same array feeds `pdf()`, deliberately: the client asked for a download
     * that looks like what was on screen, and two renderers reading two field
     * sets is how those drift apart.
     */
    public function show(Request $request, Permit $permit): JsonResponse
    {
        $this->authorizeView($request, $permit);
        $permit->load($this->eager);

        return response()->json([
            'data' => (new PermitResource($permit))->resolve()
                + ['certificate' => $this->certificateData($permit)],
        ]);
    }

    /** dompdf permit certificate (CITY OF MALABON header, QR data-URI). */
    public function pdf(Request $request, Permit $permit): Response
    {
        $this->authorizeView($request, $permit);

        $cert = $this->certificateData($permit);
        $verifyUrl = $cert['verify_url'];

        $pdf = Pdf::loadView('pdf.permit', $cert + [
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
     * Everything the certificate face prints, from the filing behind it.
     *
     * Two things are load-bearing here.
     *
     * `business` is nullable. `Business` soft-deletes and its permits stay on
     * the register, so `$permit->business` comes back null on an issued permit
     * whose business was removed — this is the same shape that took three
     * officer screens down (see RemovedBusinessRenderingTest). Every read below
     * is null-safe, and `business_name` answers null rather than inventing a
     * name, so the caller can say "removed from register" instead of blank.
     *
     * The signature block is data, never a literal. Officeholders rotate; a
     * name compiled into this file or into the blade keeps printing someone who
     * left the post until somebody redeploys. Names come from office_signatories
     * for the office that issues this permit type, and when that office has none
     * configured the block falls back to ruled lines with role captions only —
     * an empty line is honest, a guessed name is not.
     *
     * @return array<string, mixed>
     */
    private function certificateData(Permit $permit): array
    {
        /*
         * `load`, not `loadMissing`. `$this->eager` has already put a
         * `business:id,name` on this model by the time `show()` gets here, and
         * loadMissing would see the relation as present and leave it — two
         * selected columns, no owner_user_id, so `business.owner` never loads
         * and the owner's name on the certificate comes back null. Reloading
         * costs one query on a single-row read.
         */
        $permit->load([
            'permitType.department.signatories',
            'business.address.barangay',
            'business.owner',
            'business.lines.psicCode',
            'application',
        ]);

        $business = $permit->business;
        $address = $business?->address;

        $signatories = $permit->permitType?->department?->signatories
            ?->where('is_active', true)
            ->sortBy([['sort_order', 'asc'], ['role', 'asc']])
            ->map(fn ($s) => ['role' => $s->role, 'name' => $s->name])
            ->values()
            ->all() ?? [];

        return [
            'permit_number' => $permit->permit_number,
            'permit_type_name' => $permit->permitType?->name ?? 'Permit',
            'department_name' => $permit->permitType?->department?->name,
            'status_label' => $permit->status?->label(),
            // Null, not '', so the reader can tell "removed" from "unnamed".
            'business_name' => $business?->name,
            'trade_name' => $business?->trade_name,
            'owner_name' => $business?->owner?->fullName(),
            'address' => $address?->line1,
            'barangay' => $address?->barangay?->name,
            'city' => $address?->city,
            // Every declared line, joined: a permit face lists the activities it
            // covers, and a business may carry more than one.
            'line_of_business' => $business?->lines
                ->map(fn ($l) => $l->psicCode?->title)
                ->filter()
                ->implode(', ') ?: null,
            'tracking_id' => $permit->application?->tracking_id,
            'valid_from' => optional($permit->valid_from)->format('F j, Y'),
            'valid_until' => optional($permit->valid_until)->format('F j, Y'),
            'signatories' => $signatories,
            'verify_url' => rtrim((string) config('app.frontend_url'), '/').'/verify/'.$permit->permit_number,
        ];
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
