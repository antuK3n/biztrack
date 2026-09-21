<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\NotificationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin business-status management (permission owner.manage_status). Backs the
 * Owner Status page: list businesses + set active|flagged|suspended|blacklisted.
 */
class BusinessStatusController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    private const LABELS = [
        'active' => 'Active',
        'flagged' => 'Flagged',
        'suspended' => 'Suspended',
        'blacklisted' => 'Blacklisted',
    ];

    /**
     * The business roster. Paginated, newest registration first.
     *
     * 705 rows and 122 KB unpaged. `q` and `status` are here so the admin can
     * find the one business they came for instead of paging to it — a roster you
     * can only walk is a roster nobody uses.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'in:active,flagged,suspended,blacklisted'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        /*
         * `withCount` and one eager-loaded filing, rather than a query per row.
         *
         * The table prints the business's latest filing number and how many it
         * has; doing that off the relation inside the map would be two queries
         * per row and this list is paged at twenty.
         */
        $query = Business::with([
            'owner:id,name',
            /*
             * Latest by the CALENDAR, not by insertion order.
             *
             * This was `latest('id')`, and an id is the order rows went into
             * the table rather than the order the filings happened. The client
             * caught it by asking how BIZ-2026-00001 "became" BIZ-2026-00003:
             * Nena's Sari-Sari Store carries a renewal submitted 2026-08-13 as
             * id 1 and the original new filing submitted 2025-10-02 as id 3, so
             * the row showed the 2025 one and called it the latest.
             *
             * `submitted_at` is the date the register keeps, and `created_at`
             * stands in for a draft that has never been handed in — a draft has
             * no tracking id either way, so it can only ever be the fallback
             * for a business whose filings are all drafts.
             */
            'applications' => fn ($a) => $a->select('id', 'business_id', 'tracking_id', 'submitted_at', 'created_at')
                ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
                ->orderByDesc('id')
                ->limit(1),
        ])->withCount('applications');

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%{$q}%")));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $businesses = collect($page->items())
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                /*
                 * The business number under the name on the table — and it is
                 * a FILING's, which is the thing to be careful about.
                 *
                 * `BIZ-2026-…` is minted per APPLICATION (Numbering::trackingId),
                 * and the tester register already shows what follows: Nena's
                 * Sari-Sari Store holds BIZ-2026-00001 and BIZ-2026-00003,
                 * because every renewal and amendment takes a new one.
                 *
                 * So this is the LATEST — the filing an admin is most likely
                 * holding paperwork for — and the count travels with it. A bare
                 * number on a business with three filings would read as the
                 * business's own, which is the one thing it is not.
                 *
                 * Null when the business has never filed: a business exists in
                 * the register from the moment it is created, and inventing a
                 * number for it would be worse than saying it has none.
                 */
                'tracking_id' => $b->applications->first()?->tracking_id,
                'applications_count' => (int) $b->applications_count,
                'owner' => $b->owner ? ['id' => $b->owner->id, 'name' => $b->owner->name] : null,
                'status' => $b->status,
                'status_label' => self::LABELS[$b->status] ?? ucfirst((string) $b->status),
                'created_at' => optional($b->created_at)->toISOString(),
            ])->values();

        return response()->json([
            'data' => $businesses,
            'meta' => $this->pageMeta($page),
        ]);
    }

    public function updateStatus(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,flagged,suspended,blacklisted'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $from = $business->status;

        /*
         * `status_changed_at` is only touched when the status actually moves.
         * It dates a blacklisting on the Business Closure Trend, so re-saving
         * the same status — which the roster lets an admin do, and which the QA
         * sweeps in the audit log did twice — must not shift that closure into
         * the current month. Re-blacklisting an already-blacklisted business is
         * not a second closure.
         *
         * The audit row is still written either way: "an admin looked at this
         * and left it alone, for this reason" is a fact worth keeping, it is
         * just not a status change.
         */
        $changes = ['status' => $data['status']];
        if ($data['status'] !== $from) {
            $changes['status_changed_at'] = now();
        }
        $business->update($changes);

        Audit::log('business.status_changed', $business, [
            'from' => $from,
            'to' => $data['status'],
            'reason' => $data['reason'],
        ]);

        /*
         * Tell the owner — but only when something actually moved.
         *
         * Same condition as `status_changed_at` above and for the same reason:
         * the roster lets an admin re-save the status a business already has,
         * and the QA sweeps in the audit log did exactly that. An audit row for
         * "looked at and left alone" is worth keeping; a notification saying
         * "your business is now Blacklisted" for the second time, weeks later,
         * is a false alarm to the one person least able to check.
         */
        if ($data['status'] !== $from) {
            $this->notifications->businessStatusChanged(
                $business,
                (string) $from,
                $data['status'],
                $data['reason'],
                self::LABELS[$data['status']] ?? $data['status'],
            );
        }

        return response()->json([
            'data' => [
                'id' => $business->id,
                'status' => $business->status,
                'status_label' => self::LABELS[$business->status] ?? ucfirst($business->status),
            ],
        ]);
    }
}
