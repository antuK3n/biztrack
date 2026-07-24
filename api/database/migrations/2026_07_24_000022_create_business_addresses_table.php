<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->foreignId('barangay_id')->constrained();
            $table->string('city')->default('Malabon');
            $table->string('province')->default('Metro Manila');
            $table->string('postal_code')->nullable();
            // Map pin (Leaflet/OSM). PostGIS geometry deferred to S7; lat/lng now.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_addresses');
    }
};
