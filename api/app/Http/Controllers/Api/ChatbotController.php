<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\ChatbotAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The BizTrack assistant: Gemini in front of the rule-based bot, which answers
 * whenever Gemini is not configured, not asked, or fails (ChatbotAssistant).
 * One conversation per user, created lazily on first message and kept that way
 * by a unique index on user_id. Self-scoped: users only ever see their own
 * thread.
 */
class ChatbotController extends Controller
{
    public function __construct(private ChatbotAssistant $assistant) {}

    /** How many turns of the assistant transcript one request returns. */
    private const WINDOW = 200;

    /**
     * The caller's assistant transcript, oldest turn first.
     *
     * Bounded to the most recent {@see self::WINDOW} turns. The conversation is
     * per-user and never deleted, so "every turn since the account was made" is
     * the payload this was quietly heading for.
     */
    public function index(Request $request): JsonResponse
    {
        $conversation = ChatbotConversation::forUser($request->user()->id);
        if (! $conversation) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'returned' => 0, 'window' => self::WINDOW],
            ]);
        }

        $total = $conversation->messages()->count();
        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->limit(self::WINDOW)
            ->get()
            ->sortBy('id')
            ->values();

        return response()->json([
            'data' => $messages->map(fn (ChatbotMessage $m) => $this->serialize($m))->values(),
            'meta' => [
                'total' => $total,
                'returned' => $messages->count(),
                'window' => self::WINDOW,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /*
         * The chat panel shows a 4xx's own sentence in place of its "couldn't
         * reach the assistant" line, so the length refusal says what to do
         * rather than naming a "message field" nobody can see.
         */
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ], [
            'message.max' => 'Please keep your message to 2,000 characters or fewer.',
        ]);

        /*
         * Answered before the transaction opens: a Gemini request can take up
         * to its timeout, and holding a write transaction open across an HTTP
         * call would hold the conversation's rows with it.
         */
        $user = $request->user();
        $answer = $this->assistant->reply($user, $data['message']);

        [$asked, $reply] = DB::transaction(function () use ($user, $data, $answer) {
            $conversation = $this->conversationFor($user->id);

            return [
                ChatbotMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'user',
                    'body' => $data['message'],
                ]),
                // What the question was taken to be, logged beside the answer
                // (UCR-07 step 3.1); the user's own turn carries none of it.
                ChatbotMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'bot',
                    'body' => $answer->body,
                    'intent' => $answer->intent,
                    'confidence' => $answer->confidence,
                    'source' => $answer->source,
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
     *
     * createOrFirst rather than create-then-catch, because this runs inside
     * store()'s transaction. On PostgreSQL a failed INSERT aborts the whole
     * transaction, so the re-read after catching the violation failed too
     * ("current transaction is aborted") and the message was lost with a 500.
     * createOrFirst fences the insert in a savepoint, which rolls back alone.
     */
    private function conversationFor(int $userId): ChatbotConversation
    {
        return ChatbotConversation::forUser($userId)
            ?? ChatbotConversation::createOrFirst(['user_id' => $userId], ['started_at' => now()]);
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
