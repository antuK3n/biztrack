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
            // The short model name — 'Business', 'User' — not the FQCN. See
            // the note on subject filtering below.
            'auditable_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'auditable_id' => ['sometimes', 'nullable', 'integer'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = AuditLog::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        /*
         * Filter by SUBJECT — the thing acted on — and by ACTOR.
         *
         * Neither existed, and two screens were paying for it. "Status History"
         * on Business Owner Status and the activity panel on Officer Assignment
         * both wanted one record's history, so both pulled the eight newest
         * pages of the whole trail and sifted them in the browser. That is 200
         * rows out of tens of thousands, newest first, and the newest rows are
         * overwhelmingly sign-ins — so a business blacklisted last month showed
         * an empty timeline, and an officer with a real history read as having
         * done nothing. Both screens had grown a paragraph of copy explaining
         * that they could not see very far, which is a fair way to describe a
         * window and no way to run a register.
         *
         * `auditable_type` is taken as the short class name and resolved to the
         * stored FQCN here. The client should not have to know the API's
         * namespace to ask about a business, and a query string carrying
         * `App\Models\Business` is a detail that breaks the day a model moves.
         *
         * An exact match rather than a suffix LIKE, because backslash is LIKE's
         * own escape character on MySQL: a pattern ending `%\Business` asks the
         * engine to escape the B and quietly matches nothing. Separators are
         * stripped from the input before the namespace is prepended, so this
         * cannot be steered at some other namespace either.
         */
        if ($type = $request->query('auditable_type')) {
            $query->where('auditable_type', 'App\\Models\\'.str_replace(['\\', '/'], '', $type));
        }
        if ($auditableId = $request->query('auditable_id')) {
            $query->where('auditable_id', (int) $auditableId);
        }
        if ($userId = $request->query('user_id')) {
            $query->where('user_id', (int) $userId);
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
