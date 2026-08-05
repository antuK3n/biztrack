<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One site visit.
 *
 * A department can hold more than one of these on the same filing. A failed
 * visit is never overwritten — the re-inspection is a NEW row, so the record
 * reads "✗ Fire failed 02 Aug" and "○ Fire scheduled 12 Aug" and, later, that
 * the second one passed. That is the whole point: the register has to be able
 * to show that a business failed once and put it right.
 *
 * The cost of keeping that history is that "the Fire inspection" stops being a
 * single row, and every question about an office's standing on a filing has to
 * say which visit it means. `currentPerDepartment()` below is that definition,
 * and it is deliberately the only one.
 */
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

    /**
     * Keep only the CURRENT visit for each (application, department) pair.
     *
     * THE definition of "latest per department", and the only one there may be.
     * WorkflowService asks it twice for opposite reasons — once to decide
     * whether an office still needs a visit raised, once to decide whether every
     * office has passed — and two subtly different copies of the word "latest"
     * is precisely how a passing re-inspection would end up unable to approve a
     * filing that the scheduler had already decided was covered. If you need
     * this rule somewhere new, call this scope; do not restate it.
     *
     * Superseded-ness is decided on `id`, not on `scheduled_at` or
     * `conducted_at`. A re-inspection can legitimately be booked for a date
     * EARLIER than the failed visit it replaces — the failed visit's own
     * schedule may have slipped weeks past the day it was actually conducted,
     * and app 18's stuck rows are exactly that shape (scheduled 04 Aug,
     * conducted 02 Aug). The row written last is the decision taken last, and
     * that is what an office's standing has to follow. It also means a visit
     * that was never conducted is still "current" until a later one replaces
     * it, which is what makes an office with an open booking count as
     * not-yet-passed rather than as having no visit at all.
     */
    public function scopeCurrentPerDepartment(Builder $query): void
    {
        $query->whereNotExists(function ($later) {
            $later->selectRaw('1')
                ->from('inspections as later_visit')
                ->whereColumn('later_visit.application_id', 'inspections.application_id')
                ->whereColumn('later_visit.department_id', 'inspections.department_id')
                ->whereColumn('later_visit.id', '>', 'inspections.id');
        });
    }

    /**
     * Is this row the office's current visit, or has a re-inspection replaced it?
     *
     * Expressed through the scope rather than re-written as `where('id', '>')`,
     * for the reason given above: one predicate, one place.
     */
    public function isCurrent(): bool
    {
        return static::query()->whereKey($this->getKey())->currentPerDepartment()->exists();
    }

    /**
     * The visit was conducted and its result does not let the filing progress.
     *
     * `conditional` is a pass (see InspectionResult::progresses) — "passed with
     * conditions" is the officer clearing the premises with a note, not asking
     * to come back.
     */
    public function failed(): bool
    {
        return $this->status === InspectionStatus::Completed
            && $this->result !== null
            && ! $this->result->progresses();
    }

    /**
     * May an officer book a fresh visit off the back of this one?
     *
     * Three conditions, and all three are about not letting the button appear
     * where it would mean nothing:
     *
     * - the visit FAILED. A passed visit has nothing to re-inspect; offering it
     *   would be an invitation to re-open a filing that has already cleared.
     * - the filing is still FOR INSPECTION. Once it is approved, rejected or
     *   cancelled the decision is made, and scheduling a visit against it would
     *   produce a booking no transition can ever consume.
     * - this row is still the CURRENT visit. Otherwise an officer reading the
     *   history of a filing that already failed twice could schedule a third
     *   visit from the older of the two failures, and the office would end up
     *   with two open bookings, neither of which the other knows about.
     */
    public function canBeReinspected(): bool
    {
        return $this->failed()
            && $this->application?->status === ApplicationStatus::ForInspection
            && $this->isCurrent();
    }
}
