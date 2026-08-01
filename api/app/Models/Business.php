<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id', 'name', 'trade_name', 'registration_type',
        // Absent from this list, mass assignment dropped it in silence: the
        // controller set it, create() ignored it, and the Form of Organization
        // panel read null on every business the application itself registered.
        'form_of_organization',
        'registration_number', 'tin', 'ban', 'is_active', 'status',
        'is_rented', 'lessor_name', 'lessor_address', 'lessor_contact',
        'monthly_rental', 'emergency_contact_name', 'emergency_contact_number',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_rented' => 'boolean',
    ];

    /** Statuses that bar the owner from filing new applications. */
    public function isBlockedFromApplying(): bool
    {
        return in_array($this->status, ['suspended', 'blacklisted'], true);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(BusinessAddress::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BusinessLine::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }
}
