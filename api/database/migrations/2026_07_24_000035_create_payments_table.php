<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_assessment_id')->constrained();
            $table->string('reference_number')->nullable()->unique();   // PAY-YYYY-NNNNNN
            $table->decimal('amount', 12, 2);
            $table->string('method');                                   // gcash|maya|card
            $table->string('status')->default('pending');              // pending|completed|failed|refunded
            $table->string('receipt_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
