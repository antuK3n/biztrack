<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification center (polled by the frontend). Scoped to the caller.
 */
class NotificationController extends Controller
{
    /**
     * The notification centre. Paginated, newest first.
     *
     * `unread` is counted with its own query rather than off the loaded rows.
     * Counting the collection was correct only while the collection was every
     * notification the user had; the moment the list is bounded, the badge would
     * count the unread ones on page one and quietly under-report — the sort of
     * wrong number nobody reports because it always looks reasonable.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $notifications = $request->user()->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $unread = $request->user()->notifications()->whereNull('read_at')->count();

        return response()->json([
            'data' => NotificationResource::collection($notifications->items()),
            'meta' => $this->pageMeta($notifications) + ['unread' => $unread],
        ]);
    }

    public function read(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403, 'Not your notification.');

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['data' => new NotificationResource($notification)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }
}
