<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry from the classification legend CPDO prints on every barangay zoning
 * sheet (19 of them as of the 2018-2027 proposed maps).
 *
 * This is reference data an admin owns, not a constant: the maps are proposals
 * for a plan period ending 2027, so the list is expected to be revised by
 * ordinance. See the migration 2026_09_01_000010 for why it is a table.
 *
 * A classification says what appears on a map. It says nothing about a
 * particular address — the sheets are rasters and hold no geometry, so no
 * conformity verdict can be computed from these rows and none is offered.
 */
class ZoningClassification extends Model
{
    protected $fillable = ['code', 'name', 'legend_color', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** The barangays whose sheet shows this classification somewhere. */
    public function barangays(): BelongsToMany
    {
        return $this->belongsToMany(Barangay::class, 'barangay_zoning_classification');
    }
}
