<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the assistant took each question to be, and how sure it was.
 *
 * The paper (UCR-07 step 3.1, R16) promises that every exchange is logged with
 * the detected intent and a confidence score; the table held only the text.
 * `source` says who classified it (`rules` for the rule-based bot), so a
 * second classifier can be told apart in the same log.
 *
 * Bot turns only. The owner's own turn is not classified, so its three
 * columns stay null, and so do bot turns written before this ran: nothing
 * recorded what they were, and a backfill would be a guess wearing a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->string('intent', 32)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('source', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->dropColumn(['intent', 'confidence', 'source']);
        });
    }
};
