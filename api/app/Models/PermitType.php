<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PermitType extends Model
{
    /**
     * The permit an application is FOR, not a clearance you pick.
     *
     * It is attached implicitly by the wizard, it is the thing the outstanding
     * balance gates the release of, and you cannot hand in a copy of the
     * document you are asking to be issued — a renewal proves the previous one
     * through the PRIOR_PERMIT requirement instead.
     */
    public const OUTCOME_CODE = 'BUSINESS';

    /**
     * Clearances with an applicant-facing form sheet (UI prototype Parts 4-7,
     * pages 040-044; web OFFICE_FORM_CODES).
     *
     * A fact about the permit type rather than about any one screen: the
     * clearance stage needs it to say whether "Apply" opens a form, and
     * OfficeFormController needs it to decide which sheets exist at all. It
     * used to live privately on that controller, so the only way to ask the
     * question was to go through an HTTP controller.
     *
     * This list and `OFFICE_FORM_CODES` in web/src/pages/applicant/
     * OfficeFormStep.tsx are the same list written twice and must be changed
     * together: this one decides which sheets the API will accept and which
     * cards advertise a form, that one decides which sheets can be drawn. A
     * code in only one of them is either a card promising a screen that does
     * not exist, or a screen the API refuses to save.
     *
     * ZONING joined in August 2026 (checklist item 101), at first as the one
     * sheet not transcribed from a paper form; CPDD's MCG-CPDD-FO-003 v1.2
     * arrived shortly after and the sheet was rebuilt against it (see the
     * header of OfficeFormStep.tsx and questions-for-malabon E4/C9).
     *
     * MARKET was on this list and is gone [client, 2026-09-06]. It was the one
     * sheet nobody at the city had ever seen — the client said outright that no
     * paper version existed and asked for one to be invented — and it has now
     * been confirmed with the LGU that neither the Market Clearance nor its
     * office is needed. A business that genuinely needs one is asked by hand,
     * through Other Requirements. Five open a form.
     */
    public const OFFICE_FORM_CODES = ['ZONING', 'SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'];

    /**
     * Display order for the five other permits, as the client's flow lays them
     * out (docs/application-flow-2026-09.md). Codes outside this list — an LGU
     * that adds a permit type later — sort after it by id, so a new permit
     * appears at the end rather than silently vanishing from the stage.
     */
    public const CLEARANCE_ORDER = ['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY'];

    /**
     * The other permits every application must obtain before BPLO can approve
     * it (docs/application-flow-2026-09.md rule 1, verified with the client
     * against the counter procedure on 6 September 2026).
     *
     * These are now ALL of the other permits — there is no optional one. Market
     * Clearance was the exception and was removed from the system entirely on
     * 6 September 2026, so "required" and "exists" describe the same set. Two
     * consequences worth knowing before adding a permit type: an optional permit
     * has nowhere to be expressed any more, and the one bill raised at
     * submission can cover every permit precisely because none of them is a
     * choice.
     *
     * This is the list `for_final_approval` is computed from. Adding a code
     * here makes it mandatory on every NEW filing; filings already in flight
     * keep the permit set they were submitted with, because `attachRequired()`
     * runs once at submission and nothing re-derives it afterwards. That is on
     * purpose — an LGU adding a requirement must not retroactively block
     * applications people have already paid for.
     */
    public const REQUIRED_CLEARANCE_CODES = ['SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC'];

    /** Is this one of the five every application must obtain? */
    public function isRequiredClearance(): bool
    {
        return in_array($this->code, self::REQUIRED_CLEARANCE_CODES, true);
    }

    protected $fillable = [
        'code', 'name', 'permit_number_prefix', 'issuing_department_id',
        'validity_days', 'description',
        'requires_inspection', 'base_fee', 'per_line_surcharge',
    ];

    protected $casts = [
        'requires_inspection' => 'boolean',
        'validity_days' => 'integer',
        'base_fee' => 'decimal:2',
        'per_line_surcharge' => 'decimal:2',
    ];

    /** One of the five other permits, rather than the permit applied for. */
    public function isClearance(): bool
    {
        return $this->code !== self::OUTCOME_CODE;
    }

    /** Does applying for this clearance open an office form sheet? */
    public function hasOfficeForm(): bool
    {
        return in_array($this->code, self::OFFICE_FORM_CODES, true);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'issuing_department_id');
    }

    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(DocumentType::class, 'permit_type_requirements')
            ->withPivot('context', 'is_mandatory', 'notes');
    }
}
