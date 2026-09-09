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

    /**
     * The overlay zones City Ordinance No. 24-2018 designates over this
     * barangay.
     *
     * A second relation and not more rows on the one above, deliberately: an
     * overlay lies OVER the base zones rather than being one of them (Art. V §4,
     * "a transparent zone overlain on a Base Zone"), so a barangay has both at
     * once and a caller that wants base zones must not be able to receive an
     * overlay by forgetting a filter.
     *
     * Same limit as the base list: this says the ordinance designates the
     * overlay somewhere in this barangay. Which lots it covers is CPDO's to
     * determine.
     */
    public function zoningOverlays(): BelongsToMany
    {
        return $this->belongsToMany(ZoningOverlay::class, 'barangay_zoning_overlay')
            ->orderBy('zoning_overlays.sort_order');
    }
}
