<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin business-status management (permission owner.manage_status). Backs the
 * Owner Status page: list businesses + set active|flagged|suspended|blacklisted.
 */
class BusinessStatusController extends Controller
{
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
        $business->update(['status' => $data['status']]);
        Audit::log('business.status_changed', $business, [
            'from' => $from,
            'to' => $data['status'],
            'reason' => $data['reason'],
        ]);

        return response()->json([
            'data' => [
                'id' => $business->id,
                'status' => $business->status,
                'status_label' => self::LABELS[$business->status] ?? ucfirst($business->status),
            ],
        ]);
    }
}
