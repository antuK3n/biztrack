<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One round of "please fix this": a return or a refusal, kept after the owner
 * answers it. Written by App\Support\ReturnHistory; see the migration of
 * 19 October 2026 for why the live `remarks` columns could not be that record.
 */
class ApplicationReturn extends Model
{
    public const OPEN = 'open';

    public const COMPLIED = 'complied';

    /** Made moot without an answer — the filing was rejected or cancelled, or a later return replaced it. */
    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'application_id', 'permit_type_id', 'department_id', 'kind',
        'returned_by_user_id', 'returned_by_department_id', 'remarks', 'items',
        'returned_at', 'amended_at', 'status', 'complied_at', 'complied_by_user_id',
        'changes', 'closed_at', 'source',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'changes' => 'array',
            'returned_at' => 'datetime',
            'amended_at' => 'datetime',
            'complied_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function returnedByDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'returned_by_department_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id')->withTrashed();
    }

    public function compliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complied_by_user_id')->withTrashed();
    }
}
