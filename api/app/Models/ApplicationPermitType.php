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
        'status', 'mode', 'submitted_at', 'decided_at', 'remarks', 'rejection_reason',
    ];

    protected $casts = [
        'status' => ClearanceStatus::class,
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
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
