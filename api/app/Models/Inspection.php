<?php

namespace App\Models;

use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inspection extends Model
{
    protected $fillable = [
        'application_id', 'department_id', 'inspector_user_id', 'status',
        'result', 'scheduled_at', 'conducted_at', 'findings', 'photo_paths',
    ];

    protected $casts = [
        'status' => InspectionStatus::class,
        'result' => InspectionResult::class,
        'scheduled_at' => 'datetime',
        'conducted_at' => 'datetime',
        'photo_paths' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }
}
