<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business owner as the old register records them, before they have a
 * BizTrack account (Ken's checklist, 27 September 2026, "Migration 1").
 *
 * Written only by the legacy import. Claimed — `claimed_by_user_id` set, and
 * the businesses' `owner_user_id` filled — when the owner signs up and quotes
 * one of their business account or permit numbers (App\Support\LegacyImport\
 * LegacyClaim). Never carries a password: an unclaimed owner has no account to
 * sign in to, which is the point.
 *
 * Personal data under RA 10173. Nothing but the import screen and the claim
 * check reads it.
 */
class LegacyOwner extends Model
{
    protected $fillable = [
        'legacy_id', 'first_name', 'middle_name', 'last_name', 'suffix',
        'email', 'mobile_number', 'claimed_by_user_id', 'claimed_at',
    ];

    protected $casts = ['claimed_at' => 'datetime'];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name, $this->middle_name, $this->last_name, $this->suffix,
        ])));
    }
}
