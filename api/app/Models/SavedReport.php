<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reader's saved Custom Report set-up. See the 2026_10_19_090000 migration
 * for why it is per user and why the office is not stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $definition
 */
class SavedReport extends Model
{
    protected $fillable = ['user_id', 'name', 'definition'];

    protected $casts = [
        'definition' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
