<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pay a different way" on a pending online payment (docs/payment-gateway.md
 * §3a) [Ken, 2026-09-29].
 *
 *   abandoned_at      The owner set this payment aside to pay another way.
 *                     NOT a status: KwikPay's docs are plain that an open order
 *                     may still be paid, so the row stays `pending`, stays in
 *                     reconciliation and its callback is still accepted. This
 *                     only stops it being handed back as "the payment in
 *                     flight", so a new order can be opened.
 *   refund_review_at  Two payments went through for one bill because a
 *                     set-aside one turned out to be paid after all. Staff
 *                     were told; the owner sees it in their payment history.
 *
 * Additive only (AGENTS.md §2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('refund_review_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['abandoned_at', 'refund_review_at']);
        });
    }
};
