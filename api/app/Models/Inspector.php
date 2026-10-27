<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inspector in an office's pool — see the migration
 * `an_office_keeps_a_pool_of_inspectors` for why this is not a user.
 */
class Inspector extends Model
{
    /** The office week: Monday to Friday, ISO numbering. */
    public const OFFICE_DAYS = [1, 2, 3, 4, 5];

    protected $fillable = [
        'department_id', 'user_id', 'name', 'mobile_number', 'is_active',
        'works_days', 'max_visits_per_day', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'works_days' => 'array',
        'max_visits_per_day' => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barangays(): BelongsToMany
    {
        return $this->belongsToMany(Barangay::class, 'inspector_barangay');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(InspectorLeave::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /** @return list<int> */
    public function worksDays(): array
    {
        $days = $this->works_days;

        return is_array($days) && $days !== [] ? array_values(array_map('intval', $days)) : self::OFFICE_DAYS;
    }

    /** Whether this inspector goes out on that weekday (ISO 1–7). */
    public function worksOn(CarbonInterface $day): bool
    {
        return in_array($day->isoWeekday(), $this->worksDays(), true);
    }

    /** Whether a leave covers that day. Needs `leaves` loaded or queries it. */
    public function onLeave(CarbonInterface $day): ?InspectorLeave
    {
        $ymd = $day->toDateString();

        return $this->leaves->first(fn (InspectorLeave $leave) => $leave->starts_on->toDateString() <= $ymd
            && $leave->ends_on->toDateString() >= $ymd);
    }

    /** Whether this inspector covers that barangay. Needs `barangays` loaded or queries it. */
    public function covers(int $barangayId): bool
    {
        return $this->barangays->contains('id', $barangayId);
    }
}
