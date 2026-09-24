<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Block, Lot and lot area on the business location (client feedback on the
 * zoning step, 23 September 2026).
 *
 * Much of Malabon is addressed by subdivision block and lot rather than by a
 * house number, and CPDO reads a location by its lot. All three are nullable:
 * plenty of premises have no block or lot (a market stall, a unit on a named
 * street), and the wizard asks them as optional.
 *
 * `lot_area_sqm` is the LOT, not the floor. The floor area the fee engine
 * assesses is BPLO item B1 on the fee profile (`floor_area_sqm`); a shop on
 * the ground floor of a 200 sq. m. lot may occupy 30 of it, so the two are
 * different answers and neither is derived from the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_addresses', function (Blueprint $table) {
            $table->string('block', 40)->nullable()->after('street');
            $table->string('lot', 40)->nullable()->after('block');
            $table->decimal('lot_area_sqm', 12, 2)->nullable()->after('lot');
        });
    }

    public function down(): void
    {
        Schema::table('business_addresses', function (Blueprint $table) {
            $table->dropColumn(['block', 'lot', 'lot_area_sqm']);
        });
    }
};
