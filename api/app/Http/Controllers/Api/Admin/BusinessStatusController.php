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

        $query = Business::with('owner:id,name');

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
