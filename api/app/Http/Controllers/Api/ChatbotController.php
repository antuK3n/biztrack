<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\ChatbotResponder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based BizTrack assistant (no LLM). One conversation per user, created
 * lazily on first message and kept that way by a unique index on user_id.
 * Self-scoped: users only ever see their own thread.
 */
class ChatbotController extends Controller
{
    public function __construct(private ChatbotResponder $responder) {}

    public function index(Request $request): JsonResponse
    {
        $conversation = ChatbotConversation::forUser($request->user()->id);
        $messages = $conversation
            ? $conversation->messages()->orderBy('id')->get()
            : collect();

        return response()->json([
            'data' => $messages->map(fn (ChatbotMessage $m) => $this->serialize($m))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $replyBody = $this->responder->reply($user, $data['message']);

        [$asked, $reply] = DB::transaction(function () use ($user, $data, $replyBody) {
            $conversation = $this->conversationFor($user->id);

            return [
                ChatbotMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'user',
                    'body' => $data['message'],
                ]),
                ChatbotMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'bot',
                    'body' => $replyBody,
                ]),
            ];
        });

        /*
         * The reply is the payload, but the stored user turn goes back too: the
         * chat panel shows the message optimistically before the round trip, and
         * this is how it learns the row id so a later history load can recognise
         * the turn instead of showing it twice.
         */
        return response()->json([
            'data' => $this->serialize($reply),
            'meta' => ['user_message' => $this->serialize($asked)],
        ], 201);
    }

    /**
     * The user's one conversation, opened on first use. user_id is unique, so a
     * second request that raced this one loses the insert and re-reads instead.
     */
    private function conversationFor(int $userId): ChatbotConversation
    {
        $conversation = ChatbotConversation::forUser($userId);
        if ($conversation) {
            return $conversation;
        }

        try {
            return ChatbotConversation::create(['user_id' => $userId, 'started_at' => now()]);
        } catch (UniqueConstraintViolationException $e) {
            return ChatbotConversation::forUser($userId) ?? throw $e;
        }
    }

    private function serialize(ChatbotMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender' => $message->sender,
            'body' => $message->body,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
