<?php

namespace App\Models;

use App\Enums\PermitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permit extends Model
{
    protected $fillable = [
        'permit_number', 'application_id', 'business_id', 'permit_type_id',
        'status', 'valid_from', 'valid_until', 'pdf_path', 'issued_at',
        'issued_by_user_id',
    ];

    protected $casts = [
        'status' => PermitStatus::class,
        'valid_from' => 'date',
        'valid_until' => 'date',
        'issued_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    /** Days until expiry (negative if already past). */
    public function daysUntilExpiry(): int
    {
        return now()->startOfDay()->diffInDays($this->valid_until, false);
    }
}
