<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a permit is suspended, now that there is a third reason.
 *
 * Until now a suspension had two causes and the second was derivable — a
 * refused clearance still sits on the pivot, so "nothing refused" meant "the
 * business status did it" (see WorkflowService::restorePermitsForBusiness).
 * BPLO can now suspend a Mayor's Permit by hand from Change status [client,
 * 5 October 2026], and nothing derives that: without a record, an office
 * approving a clearance would quietly lift BPLO's own suspension.
 *
 * Values: `refusal` (an office refused or rejected a clearance), `business`
 * (the business was suspended or blacklisted), `filing` (BPLO rejected the
 * filing), `manual` (BPLO suspended it). Null on rows suspended before this
 * existed, which are treated as they always were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->string('suspended_cause')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->dropColumn('suspended_cause');
        });
    }
};
