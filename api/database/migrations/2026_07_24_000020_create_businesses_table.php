<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('registration_type')->nullable();   // DTI | SEC | CDA
            $table->string('registration_number')->nullable();
            $table->string('tin')->nullable();
            $table->string('ban')->nullable()->unique();        // BAN-YYYY-NNNN
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
