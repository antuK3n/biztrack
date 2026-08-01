<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paginated audit trail (audit.view). Read-only.
 */
class AuditLogController extends Controller
{
    /**
     * The audit trail. Paginated, newest first.
     *
     * `per_page` is clamped now. `->paginate((int) $request->query('per_page', 25))`
     * reads as bounded and is not: `?per_page=999999` was obeyed and returned
     * 5.1 MB — the entire audit table, every action every user has ever taken,
     * on one unremarkable GET. `per_page=-1` and `per_page=abc` both cast to a
     * value SQLite reads as "no limit". The default of 25 stays; the ceiling is
     * the shared 200.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = AuditLog::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        $logs = $query->paginate($this->perPage($request, default: 25));

        $logs->getCollection()->transform(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'user' => $log->user ? ['name' => $log->user->name] : null,
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'changes' => $log->changes,
            'created_at' => optional($log->created_at)->toISOString(),
        ]);

        return response()->json([
            'data' => $logs->items(),
            'meta' => $this->pageMeta($logs),
        ]);
    }
}
