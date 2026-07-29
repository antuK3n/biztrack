<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "One conversation per user" was a convention in ChatbotController, not a rule
 * the database enforced. firstOrCreate() is a SELECT then an INSERT, so two
 * requests arriving together could each open a conversation for the same user,
 * and the reader only ever returned one of them: everything written to the
 * other row was invisible from then on. This makes the rule a constraint.
 *
 * Any threads that already split are merged into the user's oldest conversation
 * first, so no message is dropped on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateConversations();

        Schema::table('chatbot_conversations', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_conversations', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }

    /** Re-point every duplicate's messages at the user's first conversation. */
    private function mergeDuplicateConversations(): void
    {
        $duplicated = DB::table('chatbot_conversations')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->pluck('user_id');

        foreach ($duplicated as $userId) {
            $ids = DB::table('chatbot_conversations')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->pluck('id');

            $keep = $ids->shift();

            DB::table('chatbot_messages')->whereIn('conversation_id', $ids)->update(['conversation_id' => $keep]);
            DB::table('chatbot_conversations')->whereIn('id', $ids)->delete();
        }
    }
};
