<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Paper Table 40 BPLO-form fields. Added nullable/defaulted (non-breaking).
    // App keeps its own `name`, `registration_type`, `registration_number`, `ban`, `is_active`.
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('form_of_organization')->nullable()->after('trade_name');
            $table->string('economic_organization')->nullable()->after('form_of_organization');
            $table->string('economic_organization_others')->nullable()->after('economic_organization');
            $table->string('president_officer_name')->nullable()->after('economic_organization_others');
            $table->string('citizenship')->nullable()->after('president_officer_name');
            $table->decimal('capital_participation_filipino', 5, 2)->nullable()->after('citizenship');
            $table->decimal('capital_investment', 15, 2)->nullable()->after('capital_participation_filipino');
            $table->decimal('business_area_sqm', 10, 2)->nullable()->after('capital_investment');
            $table->integer('total_employees')->nullable()->after('business_area_sqm');
            $table->integer('male_employees')->nullable()->after('total_employees');
            $table->integer('female_employees')->nullable()->after('male_employees');
            $table->integer('employees_within_lgu')->nullable()->after('female_employees');
            $table->integer('delivery_units')->nullable()->after('employees_within_lgu');
            $table->boolean('has_tax_incentives')->default(false)->after('delivery_units');
            $table->boolean('pays_rent')->default(false)->after('has_tax_incentives');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'form_of_organization', 'economic_organization', 'economic_organization_others',
                'president_officer_name', 'citizenship', 'capital_participation_filipino',
                'capital_investment', 'business_area_sqm', 'total_employees', 'male_employees',
                'female_employees', 'employees_within_lgu', 'delivery_units',
                'has_tax_incentives', 'pays_rent',
            ]);
        });
    }
};
