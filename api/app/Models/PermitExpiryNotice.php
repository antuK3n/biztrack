<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermitExpiryNotice extends Model
{
    protected $fillable = ['permit_id', 'notice_kind'];

    public function permit(): BelongsTo
    {
        return $this->belongsTo(Permit::class);
    }
}
