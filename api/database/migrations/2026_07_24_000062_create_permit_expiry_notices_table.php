<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedupe ledger for the scan-permits scheduler: one row per
        // (permit, notice kind) so repeated runs never double-notify.
        Schema::create('permit_expiry_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_id')->constrained()->cascadeOnDelete();
            // threshold_30 | threshold_15 | threshold_7 | threshold_1 (the R spec
            // §3 reminder buckets) | expired | renewal_due
            $table->string('notice_kind');
            $table->timestamps();
            $table->unique(['permit_id', 'notice_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_expiry_notices');
    }
};
