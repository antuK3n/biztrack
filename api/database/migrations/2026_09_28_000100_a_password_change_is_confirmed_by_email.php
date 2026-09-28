<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes emailed to confirm an act on an account.
 *
 * ── Why changing a password needed one ──────────────────────────────────────
 *
 * `PUT /auth/password` asked for the current password and nothing else. So a
 * session left open at a shared counter — or a borrowed phone, which is how
 * most people in Malabon reach this app — was enough to take the account: type
 * the password the owner had just typed in front of you, set a new one, and
 * every other device is signed out by the change itself. The owner's own way
 * back in is the password they no longer have.
 *
 * A code sent to the registered address breaks that, because the address is
 * the one thing the person at the counter does not have. [Client, 28 September
 * 2026: *"implement email verification in the change password"*.]
 *
 * ── Why a table and not three columns on `users` ────────────────────────────
 *
 * `purpose` is here from the start. A code is a general mechanism — changing
 * an email, confirming a deactivation, authorising a transfer are all the same
 * shape — and a second feature would otherwise mean a second trio of columns
 * and a second expiry to remember to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What this code authorises. Never trust one issued for something
            // else: a code emailed to confirm a deactivation must not also
            // change a password.
            $table->string('purpose', 40);

            /*
             * HASHED, like a password, and for the same reason. A six-digit
             * code in plain text is a credential sitting in a table that any
             * read of the database hands over — and the whole point of it is
             * that only the mailbox has it.
             */
            $table->string('code_hash');

            $table->timestamp('expires_at');

            /*
             * A six-digit code is one in a million, which a script exhausts in
             * minutes. Counting attempts is what makes the window matter.
             */
            $table->unsignedTinyInteger('attempts')->default(0);

            // Set when the code is spent, so a correct code cannot be replayed.
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            // The lookup is always "this user's live code for this purpose".
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_codes');
    }
};
