<?php

namespace App\Models;

use App\Enums\ClearanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * One requested permit on one application — the pivot row, promoted to a model.
 *
 * It became a model the day it stopped being a link. It now carries a state
 * machine (`ClearanceStatus`), which office form or uploaded image satisfies it,
 * and when the applicant and the office each did their half. A plain array
 * pivot cannot cast an enum, so every reader would have been comparing raw
 * strings against `ClearanceStatus::Approved->value` by hand — and the first
 * one to forget is a permit that never counts as approved.
 *
 * Deliberately NOT a place for behaviour. Transitions go through
 * `WorkflowService`, which is the only writer of both status columns, so that
 * history, notifications and the readiness recheck cannot be skipped by writing
 * to the pivot directly.
 */
class ApplicationPermitType extends Model
{
    use AsPivot;

    protected $table = 'application_permit_types';

    /**
     * The pivot has its own `id`, so it is a real row that can be pointed at.
     * That matters for the officer's screens: an OP admin acts on ONE permit,
     * and the route needs something to name it that is not the
     * (application, permit type) pair spelled out in the URL.
     */
    public $incrementing = true;

    protected $fillable = [
        'status', 'mode', 'submitted_at', 'decided_at',
        'remarks', 'remarks_target', 'returned_at',
        /*
         * The refusal, kept apart from `remarks` because the two have
         * opposite lifetimes: `remarks` is the CURRENT instruction and is
         * cleared the moment the applicant answers it, and these are the
         * historical fact that they were once refused, which the office needs
         * on the re-read. See the migration for why that cannot be one column.
         */
        'rejected_at', 'rejection_note', 'rejection_remedy',
    ];

    protected $casts = [
        'status' => ClearanceStatus::class,
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        // Or `->toISOString()` on it throws: an uncast timestamp comes back a
        // string, and the resources format these for the wire.
        'returned_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    /**
     * May an inspection still act on this permit?
     *
     * ── Why this is a method and not three copies of a condition ────────────
     *
     * Three places used to ask only the first half of this question — "is the
     * pivot `for_inspection`" — and none asked the second:
     *
     *  - `WorkflowService::scheduleClearanceInspection()`, booking a first visit
     *  - `Inspection::canBeReinspected()`, booking one after a failure
     *  - `WorkflowService::recordInspection()`, consuming a visit's result
     *
     * The pivot rows are never walked back when BPLO ends a filing, so on a
     * REJECTED application they all still read `for_inspection` and all three
     * doors stood open. Measured on 18 September 2026: conduct a passing visit
     * on a rejected filing and the third door answered 200, moved the permit to
     * `approved` and MINTED THE CERTIFICATE — a Fire Safety clearance issued
     * against a filing the LGU had refused. The other two book visits that no
     * transition can ever consume.
     *
     * So the question is asked in one place that both halves have to pass, and
     * the three callers ask it rather than reassembling it. The alternative
     * considered was walking the pivot rows back in `rejectApplication()`; it
     * was rejected because `ClearanceStatus::Rejected` was removed on
     * 17 September at the client's decision, leaving no state to walk them to,
     * and because leaving them where they stood is the honest record of how far
     * each permit had got when the filing was refused.
     *
     * A read, not a transition — so it does not breach the note above. It
     * decides nothing and writes nothing; `WorkflowService` remains the only
     * writer of both status columns.
     */
    public function awaitingInspection(): bool
    {
        if ($this->status !== ClearanceStatus::ForInspection) {
            return false;
        }

        // A decided filing — approved, rejected or cancelled — has no permit
        // awaiting anything. `isTerminal()` is the flow's own word for it and
        // is what `ApplicationStatus::allowedNext()` already enforces on the
        // filing's own status; this carries the same guard down to the permit.
        return ! ($this->application?->status?->isTerminal() ?? false);
    }

    /** The applicant filled this office's form. */
    public const MODE_APPLY = 'apply';

    /**
     * The applicant uploaded a permit they already hold.
     *
     * The office sees the image and no form — there is nothing to read but the
     * document itself. It does NOT skip the inspection: the LGU inspects the
     * premises, not the paperwork, so an uploaded permit is scheduled and
     * visited like any other (docs/application-flow-2026-09.md rule 3).
     */
    public const MODE_UPLOAD = 'upload';
}
