<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Six-digit codes sent by e-mail [checklist 2026-09-27, Register 1 and Login 5].
 *
 * One table for both uses, because they are the same object: a short number
 * sent to an address, good for a few minutes and a few tries, stored only as a
 * hash.
 *
 *   purpose = 'verify'  confirms the address after sign-up. Tied to the account.
 *   purpose = 'login'   the second step after a correct password. Tied to the
 *                       account AND to a `challenge`: the random handle the
 *                       sign-in page holds between the two steps, so a code is
 *                       only accepted by the attempt that asked for it.
 *
 * `code_hash` and `challenge_hash` are hashes, never the values. A copy of this
 * table (a backup, a leaked SQLite file) must not hand anyone a working code
 * or a half-finished sign-in.
 *
 * `attempts` counts wrong guesses and is never reset by a resend: five guesses
 * per sign-in, not five per e-mail. `send_count` and `sent_at` ration the
 * resend button. `consumed_at` marks a code used or given up on, so it cannot
 * be replayed.
 *
 * Additive only. Nothing existing is touched, so this is safe to run on its
 * own against the live register (AGENTS.md §2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->string('challenge_hash', 64)->nullable()->unique();
            $table->string('portal', 16)->nullable();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_codes');
    }
};
