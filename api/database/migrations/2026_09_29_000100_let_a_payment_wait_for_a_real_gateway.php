<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment that is not settled the instant it is made (docs/payment-gateway.md).
 *
 * Until now every payment was simulated: `PaymentGateway::charge()` wrote the
 * row as completed in the same request, so `payments` never needed to remember
 * anything about a payment still in flight. A real gateway (KwikPay) settles
 * later — by a callback, or by us asking — so a pending row now has to carry
 * where the owner was sent to pay and how often we have asked about it.
 *
 *   gateway          'simulated' | 'kwikpay'. Which path MADE the payment. The
 *                    on/off switch only decides how NEW payments are made, so a
 *                    row has to say which one it came from: a KwikPay payment
 *                    opened before the switch was turned off is still reconciled
 *                    against KwikPay. Existing rows are all simulated, which is
 *                    what the default records.
 *   gateway_order_id The `order_id` sent to KwikPay: the PAY- reference plus a
 *                    random tail. The reference alone is NOT unique enough —
 *                    Numbering counts per database, and the live register, the
 *                    demo and every e2e copy would all mint PAY-2026-000123
 *                    against the one merchant account, where KwikPay rejects a
 *                    reused order_id outright (409 DUPLICATE, docs FAQ).
 *   pay_url          The payment page or QR image KwikPay handed back.
 *   pay_url_kind     'link' (send the owner there) or 'qr' (show it to scan).
 *   next_check_at    When reconciliation may next ask KwikPay (backoff).
 *   check_attempts   How many times it has asked.
 *   flagged_at       Still pending after a day: staff should look. Never an
 *                    automatic failure — "no answer" is not "not paid".
 *   gateway_note     The last thing the gateway said, in its words, for staff.
 *
 * `settings` is new too: a small key/value table for switches an administrator
 * flips at runtime. The payment mode is the first. There was no settings table
 * to reuse — every other switch in the product is env config.
 *
 * Additive only: new columns with defaults and a new table. Safe to run on its
 * own against the live register (AGENTS.md §2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway', 16)->default('simulated');
            $table->string('gateway_order_id', 64)->nullable()->unique();
            $table->text('pay_url')->nullable();
            $table->string('pay_url_kind', 8)->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('check_attempts')->default(0);
            $table->timestamp('flagged_at')->nullable();
            $table->string('gateway_note')->nullable();

            $table->index(['gateway', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'status']);
            $table->dropUnique(['gateway_order_id']);
            $table->dropColumn([
                'gateway', 'gateway_order_id', 'pay_url', 'pay_url_kind', 'next_check_at',
                'check_attempts', 'flagged_at', 'gateway_note',
            ]);
        });

        Schema::dropIfExists('settings');
    }
};
