<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paper Table 41 — personal owner details (supports multiple owners). */
class BusinessOwner extends Model
{
    protected $fillable = [
        'business_id', 'surname', 'given_name', 'middle_name',
        'suffix', 'gender', 'is_primary',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * "Given Middle Surname Suffix", the order `User::fullName()` prints, so a
     * permit reads the same whichever of the two it names. Empty when no part
     * is held.
     */
    public function fullName(): string
    {
        return trim(collect([$this->given_name, $this->middle_name, $this->surname, $this->suffix])
            ->filter()->implode(' '));
    }
}
