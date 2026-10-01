<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What KwikPay was asked to collect for a payment.
 *
 * Normally the bill itself. While testing with real money, KWIKPAY_CHARGE_OVERRIDE
 * asks KwikPay for a token amount (₱1.00) instead, and the bill, the payment
 * record and the receipt keep the real assessed amount. A confirmation is then
 * checked against this column, not against `amount`, so a forged callback for
 * any other figure is still refused. Null on every payment opened before it
 * existed: those were asked for their full `amount`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('gateway_amount', 12, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_amount');
        });
    }
};
