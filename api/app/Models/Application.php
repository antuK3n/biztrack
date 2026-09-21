<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tracking_id', 'business_id', 'applicant_user_id', 'application_type', 'title',
        'status', 'prior_permit_id', 'submitted_at', 'deadline_at', 'decided_at',
        // Set at submission from Support\Ra11032. Absent from this list, mass
        // assignment drops the tier in silence, and the RA 11032 panel — which
        // requires a non-null complexity — cannot see the filing at all.
        'complexity',
        /*
         * WHO set the tier, and when. Null means it came from
         * `Support\Ra11032::tierFor()` — our rule, not the LGU's published
         * classification (open question A10) — which is what the review sheet
         * shows an officer so they know they are overriding a guess rather
         * than filling in a blank. WorkflowService::classify() is the only
         * writer; see the migration for the whole argument.
         */
        'complexity_set_by_user_id', 'complexity_set_at',
        'rejection_reason', 'fee_profile', 'payment_mode',
        /*
         * RA 10173 consent for THIS filing. Absent from this list the column is
         * dropped by mass assignment in silence, which is how it stayed empty on
         * every row while a column for it sat on the table — the wizard held the
         * tick in browser state and nothing ever carried it here.
         */
        'data_privacy_consent',
        /*
         * The paper BPLO form's "Amendment from:" block (checklist items 82/84).
         * The manuscript-alignment migration created these columns and nothing
         * ever wrote them, so /apply?type=amendment was the new-application
         * wizard with a different title — it never asked the one question that
         * makes a filing an amendment. Mass assignment is how the controller
         * fills them, so they have to be listed or the answer is dropped in
         * silence exactly as `complexity` was above.
         *
         * `has_amendments` is derived, never taken from the client: it is a
         * summary of the other four and a caller that could set it independently
         * could claim an amendment amending nothing.
         */
        'has_amendments', 'amendment_ownership', 'amendment_location',
        'amendment_nature', 'amendment_other',
        /*
         * Section A3 of the renewal form — what the business structure changed
         * FROM and TO. Listed for the same reason the four above are: mass
         * assignment is how the controller writes them, and an unlisted column
         * is dropped in silence rather than refused.
         */
        'amendment_from_registration_type', 'amendment_to_registration_type',
        /*
         * ── RETIRED 18 September 2026. Kept readable, no longer written. ─────
         *
         * The applicant saying, in as many words, that there is no BizTrack
         * permit to point at — their last one was issued on paper by the old
         * counter process. It was built because year one was expected to be
         * mostly those, and it fixed a real bug: a renewal that named nothing
         * because the question was skipped used to be indistinguishable from one
         * that named nothing because there was nothing to name.
         *
         * The client then ruled the case out entirely — *"There could be no
         * cases where the permit was renewed on a different/manual system"* — so
         * a renewal names its permit or it is a mis-filed New Application, and
         * the flag has nothing left to distinguish.
         *
         * Still listed here, and still cast below, because the COLUMN survives:
         * one approved filing holds it true, which was a real answer honestly
         * given under the rule of the day, and a dropped column would erase it.
         * Nothing writes it — the two controllers that did were changed — so it
         * defaults false on every new filing and reads as history.
         */
        'prior_permit_declared_none',
    ];

    protected $casts = [
        'status' => ApplicationStatus::class,
        'application_type' => ApplicationType::class,
        'submitted_at' => 'datetime',
        'deadline_at' => 'datetime',
        'decided_at' => 'datetime',
        'complexity_set_at' => 'datetime',
        'fee_profile' => 'array',
        // Without these, SQLite hands back 0/1 and the JSON payload says
        // `"amendment_ownership": 1`, which the officer screen renders as a
        // number rather than a ticked box.
        'has_amendments' => 'boolean',
        'amendment_ownership' => 'boolean',
        'amendment_location' => 'boolean',
        'amendment_nature' => 'boolean',
        'prior_permit_declared_none' => 'boolean',
        // Same reason as the four above: SQLite hands back 0/1, and the submit
        // gate reads this as a boolean.
        'data_privacy_consent' => 'boolean',
    ];

    /**
     * The amendment kinds ticked on this filing, as the paper form's four
     * checkboxes. "Others" is the free text itself — a specified other IS the
     * tick, which is why there is no fifth boolean column to keep in step
     * with it.
     *
     * @return array<int, string>
     */
    public function amendmentKinds(): array
    {
        /*
         * ── An AMENDMENT names itself through its requested changes ──────────
         *
         * The four booleans below are Section A of the RENEWAL form — "has
         * anything changed since last year", four coarse categories. They were
         * shown on the amendment form too until 19 September 2026, and the
         * client spotted what that made of it: the applicant answered the same
         * question twice, in two vocabularies that did not line up. Two of the
         * four categories (Ownership, Nature of Business) name things the LGU
         * does not let anybody amend, while three details that ARE amendable —
         * floor area, employees, trade name — had no category of their own and
         * hid under "Others".
         *
         * So on an amendment the answer is derived from what was actually
         * asked for, which is the same information said once and precisely.
         * "Floor area (sqm), Trade name" tells the officer more than "Others:
         * area and signage" ever did.
         *
         * Falls through to the booleans when there are no requested changes,
         * so a filing made before this existed still describes itself.
         */
        if ($this->application_type === ApplicationType::Amendment) {
            $asked = $this->requestedChanges
                ->filter(fn (ApplicationAmendment $row) => $row->new_value !== null)
                ->map(fn (ApplicationAmendment $row) => $row->label())
                ->values()
                ->all();

            if ($asked !== []) {
                return $asked;
            }
        }

        return array_values(array_filter([
            $this->amendment_ownership ? 'Ownership' : null,
            $this->amendment_location ? 'Location' : null,
            $this->amendment_nature ? 'Nature of Business' : null,
            filled($this->amendment_other) ? 'Others: '.$this->amendment_other : null,
        ]));
    }

    /**
     * The business details this amendment asks to change.
     *
     * `requestedChanges` and NOT `amendments`, which is already taken twice
     * over and means something else both times: the four `amendment_*` booleans
     * on this model are the RENEWAL form's Section A declaration, and
     * `ApplicationResource` publishes them under an `amendments` key. A third
     * thing called amendments, carrying the only rows that can actually change
     * the register, is how a reader picks the wrong one.
     *
     * Empty on every filing that is not an amendment, which is the ordinary
     * case and not a gap.
     */
    public function requestedChanges(): HasMany
    {
        return $this->hasMany(ApplicationAmendment::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /**
     * The officer who set the RA 11032 tier, if a person set it at all.
     *
     * Null is the ordinary case and means the tier was classified
     * automatically at submission. `withTrashed` is deliberate: `User`
     * soft-deletes and its filings outlive it, and a reclassification whose
     * author has since left the LGU is still a reclassification a person made
     * — resolving to null there would silently relabel it as automatic, which
     * is the one thing this relation exists to prevent.
     */
    public function complexitySetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complexity_set_by_user_id')->withTrashed();
    }

    /**
     * The permits this filing asks for, each carrying its OWN progress.
     *
     * The pivot is no longer just a link — it holds the per-permit state machine
     * (`ClearanceStatus`), so every read of this relation needs `withPivot` or
     * the status silently comes back missing rather than wrong. That is the one
     * failure mode worth knowing about here: `$app->permitTypes` without the
     * pivot columns yields models whose `pivot->status` is null, which reads as
     * "not started" to anything that casts it and is how a filing would appear
     * to have lost six approvals.
     *
     * `withTimestamps` so `updated_at` on the pivot moves when a permit
     * progresses; ProcessingTimeAnalytics measures per-office service time off
     * assignments, but the pivot is the only row that knows when the APPLICANT
     * did their half.
     */
    public function permitTypes(): BelongsToMany
    {
        return $this->belongsToMany(PermitType::class, 'application_permit_types')
            ->withPivot([
                'status', 'mode', 'submitted_at', 'decided_at',
                // The return note, the thing it points at, and when it was sent
                // back. 'rejection_reason' was here until clearance-level
                // rejection was removed on 17 September 2026.
                'remarks', 'remarks_target', 'returned_at',
            ])
            ->withTimestamps()
            ->using(ApplicationPermitType::class);
    }

    /**
     * The other permits — everything except the Mayor's / Business Permit.
     *
     * BPLO's row is on the same pivot and must be excluded from every "are they
     * all approved?" question, because it is the one waiting on the answer:
     * including it makes `for_final_approval` unreachable, since the business
     * permit is only approved once BPLO has given the final sign-off that this
     * predicate gates. A circular wait that presents as filings stuck forever.
     */
    public function otherPermitTypes(): BelongsToMany
    {
        return $this->permitTypes()->where('permit_types.code', '!=', PermitType::OUTCOME_CODE);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ApplicationAssignment::class);
    }

    public function feeAssessment(): HasOne
    {
        return $this->hasOne(FeeAssessment::class);
    }

    /**
     * Will this filing ever be asked for money? No, if it is a clearance-only
     * renewal.
     *
     * ── The client's rule, and why it needed a predicate ──────────────────────
     *
     * 17 September 2026: *"The payment for each permit will also happen ONLY
     * WHEN a business permit was renewed on January."* So a sanitary permit
     * renewed in June is issued unbilled, and its fee is swept onto the next
     * business-permit renewal (see `WorkflowService::recordDeferredFee`).
     *
     * That broke the clearance stage the moment it was tried. TWO places gate
     * the stage on payment — `WorkflowService::startClearance` and
     * `ClearanceService::isUnlocked` — and a filing that will never be paid was
     * refused by both, for ever: *"The other permits open once this application
     * is paid."* The applicant could not start the very permit the filing
     * existed to renew. One rule in one place, asked by both, rather than two
     * copies of a payment test that has now grown an exception.
     *
     * Keyed on the PERMIT SET, not the calendar. There is no January lock
     * ("don't add a lock in our system yet"), so what makes a filing the one
     * that collects is that it carries the business permit — whenever it is
     * filed — and what makes this one defer is that it does not.
     *
     * A NEW filing is never deferred, whatever it carries: rule 1 of the
     * September flow bills everything at submission. An AMENDMENT is treated as
     * new, for the same reason `attachRequiredPermitTypes` leaves it on that
     * path — its shape is a question the client has said they will take
     * separately.
     */
    public function defersPayment(): bool
    {
        /*
         * ── An AMENDMENT always defers ───────────────────────────────────────
         *
         * Client, 19 September 2026: *"All amendment payments will reflect when
         * a business permit is renewed, just like the payments for other
         * permits."*
         *
         * So the same rule the other permits got in §7 of
         * docs/renewal-2026-09-17.md, and for the same reason it was a rule
         * there: the LGU collects once a year, at the January counter, and a
         * mid-year filing that asked for money separately would be a second
         * trip nobody wanted. Unconditional here — every amendment defers,
         * whatever it changes — because the condition is about WHEN the LGU
         * collects, not about what was filed.
         *
         * It also removes a mis-billing: an amendment was priced through the
         * ordinary permit assessment, which quoted ₱6,425 for a floor-area
         * correction (measured) — the whole annual permit charged again.
         */
        if ($this->application_type === ApplicationType::Amendment) {
            return true;
        }

        if ($this->application_type !== ApplicationType::Renewal) {
            return false;
        }

        return ! $this->permitTypes()
            ->where('permit_types.code', PermitType::OUTCOME_CODE)
            ->exists();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }

    public function priorPermit(): BelongsTo
    {
        return $this->belongsTo(Permit::class, 'prior_permit_id');
    }

    /**
     * Every permit this renewal covers, primary included.
     *
     * `priorPermit()` above still answers "which permit does this renew" and
     * still keys the renewal chain. This answers the different question the
     * counter actually asks — a shop holding a Mayor's Permit, a Sanitary
     * Permit and an FSIC renews all three in one visit — and the set contains
     * the primary as well, so a reader wanting the whole list never has to
     * union two sources and hope they agree.
     *
     * An empty set now means the question is unanswered, and a renewal cannot
     * submit in that state (see ApplicationController's submit gate). It used
     * to be an ordinary state — a renewal of a permit issued on paper had no
     * rows here and `prior_permit_declared_none` instead — but the client
     * retired the paper case on 18 September 2026. Drafts still pass through
     * empty, because a draft is allowed to be half-answered.
     */
    public function priorPermits(): BelongsToMany
    {
        return $this->belongsToMany(Permit::class, 'application_prior_permits')->withTimestamps();
    }

    /**
     * The conversations on this filing — one per office, never one per filing.
     *
     * This was `messageThread(): HasOne` while `message_threads.application_id`
     * was unique. It is plural now because a filing routed to six offices is
     * six separate conversations, and the singular relation would have handed
     * every caller an arbitrary one of them.
     */
    public function messageThreads(): HasMany
    {
        return $this->hasMany(MessageThread::class);
    }

    public function officerRequests(): HasMany
    {
        return $this->hasMany(OfficerRequest::class);
    }

    public function officeForms(): HasMany
    {
        return $this->hasMany(ApplicationOfficeForm::class);
    }
}
