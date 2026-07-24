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

    public function index(): JsonResponse
    {
        $businesses = Business::with('owner:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'owner' => $b->owner ? ['id' => $b->owner->id, 'name' => $b->owner->name] : null,
                'status' => $b->status,
                'status_label' => self::LABELS[$b->status] ?? ucfirst((string) $b->status),
                'created_at' => optional($b->created_at)->toISOString(),
            ])->values();

        return response()->json(['data' => $businesses]);
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
