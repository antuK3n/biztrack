<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Data-dictionary sweep (Tables 27-59): add genuinely-missing columns as
    // nullable/defaulted (non-breaking). Renames and documented deltas are
    // recorded in docs/db-solidification.md.
    public function up(): void
    {
        // barangays (Table 27) — official code
        Schema::table('barangays', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('id');
        });

        // psic_codes (Table 28) — broader category (app keeps `title` for `description`)
        Schema::table('psic_codes', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
        });

        // business_addresses (Table 42)
        Schema::table('business_addresses', function (Blueprint $table) {
            $table->string('address_type')->default('business_location')->after('business_id');
            $table->string('house_bldg_no')->nullable()->after('address_type');
            $table->string('street')->nullable()->after('house_bldg_no');
            $table->string('telephone')->nullable()->after('postal_code');
            $table->string('mobile_number')->nullable()->after('telephone');
            $table->string('email')->nullable()->after('mobile_number');
            $table->string('website')->nullable()->after('email');
        });

        // business_lines (Table 43)
        Schema::table('business_lines', function (Blueprint $table) {
            $table->string('line_of_business')->nullable()->after('psic_code_id');
            $table->text('products_services')->nullable()->after('line_of_business');
            $table->decimal('gross_sales', 15, 2)->nullable()->after('products_services');
        });

        // applications (Table 44) — amendment/mode/complexity/receipt fields
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('has_amendments')->default(false)->after('application_type');
            $table->boolean('amendment_ownership')->default(false)->after('has_amendments');
            $table->boolean('amendment_location')->default(false)->after('amendment_ownership');
            $table->boolean('amendment_nature')->default(false)->after('amendment_location');
            $table->string('amendment_other')->nullable()->after('amendment_nature');
            $table->string('mode_of_payment')->nullable()->after('amendment_other'); // annually|semi_annually|quarterly
            $table->string('complexity')->nullable()->after('mode_of_payment');       // simple|complex|highly_technical
            $table->timestamp('date_received')->nullable()->after('submitted_at');
            $table->foreignId('received_by_user_id')->nullable()->after('date_received')
                ->constrained('users')->nullOnDelete();
            $table->boolean('data_privacy_consent')->default(false)->after('received_by_user_id');
        });

        // application_documents (Table 45) — verification + integrity fields
        Schema::table('application_documents', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('size_bytes');
            $table->foreignId('uploaded_by_user_id')->nullable()->after('file_hash')
                ->constrained('users')->nullOnDelete();
            $table->string('verification_status')->default('pending')->after('uploaded_by_user_id'); // pending|verified|rejected
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('verified_by_user_id');
        });

        // application_status_history (Table 46) — department handling at that time
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('changed_by_user_id')
                ->constrained('departments')->nullOnDelete();
        });

        // permits (Table 50) — QR/hash/revocation vault fields
        Schema::table('permits', function (Blueprint $table) {
            $table->string('qr_code')->nullable()->after('pdf_path');
            $table->string('document_hash', 64)->nullable()->after('qr_code');
            $table->timestamp('revoked_at')->nullable()->after('document_hash');
            $table->text('revoked_reason')->nullable()->after('revoked_at');
        });

        // inspections (Table 51) — type + geotagged location
        Schema::table('inspections', function (Blueprint $table) {
            $table->string('inspection_type')->nullable()->after('application_id'); // sanitary|fire_safety|zoning|building|environmental
            $table->text('location_address')->nullable()->after('conducted_at');
            $table->decimal('latitude', 10, 7)->nullable()->after('location_address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        // fee_assessments (Table 52) — assessor audit trail
        Schema::table('fee_assessments', function (Blueprint $table) {
            $table->foreignId('assessed_by_user_id')->nullable()->after('adjusted_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable()->after('assessed_by_user_id');
        });

        // messages (Table 55) — read receipt + inline attachment path
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('body');
            $table->timestamp('read_at')->nullable()->after('attachment_path');
        });

        // message_threads (Table 54) — subject + status
        Schema::table('message_threads', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('application_id');
            $table->string('status')->default('open')->after('subject'); // open|closed|archived
        });

        // app_notifications (Table 56) — structured data, channel, sent_at
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->json('data')->nullable()->after('link');
            $table->string('channel')->default('in_app')->after('data'); // in_app|email|sms|push
            $table->timestamp('sent_at')->nullable()->after('read_at');
        });

        // audit_logs (Table 57) — user agent (entity_type/id map to auditable_*)
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->text('user_agent')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('barangays', fn (Blueprint $t) => $t->dropColumn('code'));
        Schema::table('psic_codes', fn (Blueprint $t) => $t->dropColumn('category'));
        Schema::table('business_addresses', fn (Blueprint $t) => $t->dropColumn([
            'address_type', 'house_bldg_no', 'street', 'telephone', 'mobile_number', 'email', 'website',
        ]));
        Schema::table('business_lines', fn (Blueprint $t) => $t->dropColumn([
            'line_of_business', 'products_services', 'gross_sales',
        ]));
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn([
            'has_amendments', 'amendment_ownership', 'amendment_location', 'amendment_nature',
            'amendment_other', 'mode_of_payment', 'complexity', 'date_received',
            'received_by_user_id', 'data_privacy_consent',
        ]));
        Schema::table('application_documents', fn (Blueprint $t) => $t->dropColumn([
            'file_hash', 'uploaded_by_user_id', 'verification_status', 'verified_at',
            'verified_by_user_id', 'rejection_reason',
        ]));
        Schema::table('application_status_history', fn (Blueprint $t) => $t->dropColumn('department_id'));
        Schema::table('permits', fn (Blueprint $t) => $t->dropColumn([
            'qr_code', 'document_hash', 'revoked_at', 'revoked_reason',
        ]));
        Schema::table('inspections', fn (Blueprint $t) => $t->dropColumn([
            'inspection_type', 'location_address', 'latitude', 'longitude',
        ]));
        Schema::table('fee_assessments', fn (Blueprint $t) => $t->dropColumn([
            'assessed_by_user_id', 'assessed_at',
        ]));
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn(['attachment_path', 'read_at']));
        Schema::table('message_threads', fn (Blueprint $t) => $t->dropColumn(['subject', 'status']));
        Schema::table('app_notifications', fn (Blueprint $t) => $t->dropColumn(['data', 'channel', 'sent_at']));
        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn('user_agent'));
    }
};
