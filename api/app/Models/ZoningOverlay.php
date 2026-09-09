<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One overlay zone designated by City Ordinance No. 24-2018 (Art. IV §3,
 * regulated in Art. V §4). Three of them: Flood, Heritage, Eco-Tourism.
 *
 * An overlay is NOT a base classification and is deliberately not a row in
 * `zoning_classifications`. Art. V §4 calls it "a transparent zone overlain on a
 * Base Zone" — it lies over the base zones rather than replacing one, so a
 * barangay carries both at once. See the migration 2026_09_01_000020 for why
 * that is a separate table rather than a `kind` column.
 *
 * The other difference is where the row comes from. A base classification was
 * read off the pixels of a CPDO sheet; an overlay is designated in the
 * ordinance's text and in Annex C's map index. Same barangay, two different
 * documents, two different people who can correct us.
 *
 * As with the base classifications, this says nothing about a particular
 * address. Which lots inside a barangay an overlay actually covers is CPDO's to
 * determine, and no verdict may be computed from these rows.
 */
class ZoningOverlay extends Model
{
    protected $fillable = ['code', 'name', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** The barangays the ordinance designates this overlay over. */
    public function barangays(): BelongsToMany
    {
        return $this->belongsToMany(Barangay::class, 'barangay_zoning_overlay');
    }
}
