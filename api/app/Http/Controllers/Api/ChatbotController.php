<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\ChatbotResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based BizTrack assistant (no LLM). One conversation per user, created
 * lazily on first message. Self-scoped: users only ever see their own thread.
 */
class ChatbotController extends Controller
{
    public function __construct(private ChatbotResponder $responder) {}

    public function index(Request $request): JsonResponse
    {
        $conversation = ChatbotConversation::where('user_id', $request->user()->id)->first();
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

        $reply = DB::transaction(function () use ($user, $data, $replyBody) {
            $conversation = ChatbotConversation::firstOrCreate(
                ['user_id' => $user->id],
                ['started_at' => now()],
            );

            ChatbotMessage::create([
                'conversation_id' => $conversation->id,
                'sender' => 'user',
                'body' => $data['message'],
            ]);

            return ChatbotMessage::create([
                'conversation_id' => $conversation->id,
                'sender' => 'bot',
                'body' => $replyBody,
            ]);
        });

        return response()->json(['data' => $this->serialize($reply)], 201);
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
