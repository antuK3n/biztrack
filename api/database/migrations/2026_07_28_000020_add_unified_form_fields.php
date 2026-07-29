<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the DILG/DTI unified business permit application form asks for that
 * the wizard did not collect (tester checklist item 2).
 *
 * Lessor details and the emergency contact belong to the business: they
 * describe the premises and the people, not one filing. The payment mode is
 * per application, because an owner may pay annually one year and quarterly
 * the next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('is_rented')->default(false)->after('tin');
            $table->string('lessor_name')->nullable()->after('is_rented');
            $table->string('lessor_address')->nullable()->after('lessor_name');
            $table->string('lessor_contact', 40)->nullable()->after('lessor_address');
            $table->decimal('monthly_rental', 12, 2)->nullable()->after('lessor_contact');
            $table->string('emergency_contact_name')->nullable()->after('monthly_rental');
            $table->string('emergency_contact_number', 40)->nullable()->after('emergency_contact_name');
        });

        Schema::table('applications', function (Blueprint $table) {
            // Ordinance Sec. 2N: annual (first 20 days of January) or quarterly
            // (first 20 days of Jan/Apr/Jul/Oct). The ordinance offers no
            // semi-annual option, so neither does this.
            $table->string('payment_mode', 20)->default('annual')->after('application_type');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'is_rented', 'lessor_name', 'lessor_address', 'lessor_contact',
                'monthly_rental', 'emergency_contact_name', 'emergency_contact_number',
            ]);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });
    }
};
