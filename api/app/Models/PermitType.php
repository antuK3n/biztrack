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
     */
    public const OFFICE_FORM_CODES = ['SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'];

    /**
     * Display order for the six clearances, as the client's flow lays them out
     * (docs/clearances-before-payment.md §"The flow"). Codes outside this list
     * — an LGU that adds a permit type later — sort after it by id, so a new
     * clearance appears at the end rather than silently vanishing from the
     * step.
     */
    public const CLEARANCE_ORDER = ['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY', 'MARKET'];

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

    /** One of the six supporting clearances, rather than the permit applied for. */
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
