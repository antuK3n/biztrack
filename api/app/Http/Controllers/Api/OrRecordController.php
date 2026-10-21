<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrRecordResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OR Records (thesis panel revisions, matrix item 5): "The OR number, date
 * paid, amount, and linked application are stored, and the records can be
 * searched and viewed."
 *
 * A record is a payment carrying an Official Receipt — today that is a counter
 * payment BPLO marked paid with its OR (PaymentController::counterPayment).
 * Online payments have none and are not listed; nor is a counter payment
 * recorded before the OR was asked for, which has none either.
 *
 * Read by BPLO and the super admin only, on `application.view_any_office`
 * (the route): the two readers of the whole register, and the same two the
 * proof behind a record is shown to. A clearance office or an owner is
 * refused by the middleware.
 */
class OrRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            // Bounded for the reason ApplicationController bounds its own: it
            // goes into unanchored LIKE patterns.
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Payment::query()
            ->whereNotNull('or_number')
            ->with([
                'application:id,tracking_id,business_id',
                'application.business:id,name',
                'recordedBy:id,name',
                // The photos behind the record; see OrRecordResource for which one is shown.
                'proofs' => fn ($proofs) => $proofs->whereNotNull('file_path'),
            ]);

        // The three things the search box names: OR no., tracking ID, business.
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->whereLike('or_number', "%{$q}%")
                    ->orWhereHas('application', fn ($a) => $a
                        ->whereLike('tracking_id', "%{$q}%")
                        ->orWhereHas('business', fn ($b) => $b->whereLike('name', "%{$q}%")));
            });
        }

        /*
         * Newest first by the date paid, which is the date the table shows,
         * so the column reads in order down the page; the newest recording
         * breaks a tie. Ordering by when BPLO recorded it instead would put an
         * OR dated last week above one dated yesterday whenever the older one
         * was entered later, and the column would look shuffled.
         */
        $records = $query->orderByDesc('or_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => OrRecordResource::collection($records->items()),
            'meta' => $this->pageMeta($records),
        ]);
    }
}
