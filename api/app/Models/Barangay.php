<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Barangay extends Model
{
    protected $fillable = ['name', 'zoning_map_path'];

    /**
     * The zoning classifications that appear somewhere on this barangay's
     * official CPDO sheet.
     *
     * "Somewhere on the sheet" is the whole of the claim. The maps are raster
     * images with no geometry, so this cannot and does not say which
     * classification covers a given address — CPDO determines that during
     * processing. Anything that reads this list must present it as a list of
     * what the map shows, never as a verdict on a location.
     */
    public function zoningClassifications(): BelongsToMany
    {
        return $this->belongsToMany(ZoningClassification::class, 'barangay_zoning_classification')
            ->orderBy('zoning_classifications.sort_order');
    }
}
