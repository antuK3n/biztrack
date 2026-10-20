<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Permit;
use App\Models\PermitType;
use App\Services\NotificationService;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Occupancy Permit's yearly inspection.
 *
 * Client, 20 October 2026: *"It is stated to us that it is only annual
 * inspection instead of annual renewal (like the other permits)."* The permit
 * is issued once and never expires (PermitType::NO_EXPIRY_CODES); its
 * `valid_until` is the day the next inspection is due.
 *
 * ── Why it is an application, and not a table of its own ────────────────────
 *
 * The first design was an `annual_inspections` table with its own list and
 * its own Record result dialog. The client asked instead that it be *"just
 * like a regular 'For inspection'"*, with *"the same RULINGS as the regular
 * inspection"*. A parallel decision path is two rule sets that drift, so the
 * entry is an application of type `annual_inspection` carrying the OCCUPANCY
 * clearance already at For Inspection, routed to OBO. Booking, moving and
 * recording the visit, re-inspection after a failure, and refusing the permit
 * are the regular WorkflowService acts, with the regular consequences: a
 * failed visit or a refusal suspends the business's live Business Permit
 * (`suspendOutcomePermit`, as for any one-permit filing) and a later pass
 * brings it back (`reconsiderSuspension`).
 *
 * Three things differ, each asked of `is()` where it happens: there is no fee
 * (`Application::defersPayment` is true, so nothing is billed or deferred), no
 * wizard, and a pass mints nothing — `grantClearance` moves the existing
 * permit's next inspection a year on from the pass (`passed()` below) instead
 * of issuing a certificate. It is kept out of the application counts through
 * `ApplicationType::NOT_FILINGS`.
 */
final class AnnualInspection
{
    /** Is this the system-opened yearly inspection, not a filing? */
    public static function is(?Application $app): bool
    {
        return $app?->application_type === ApplicationType::AnnualInspection;
    }

    /**
     * Open an entry for every Occupancy Permit whose inspection is due.
     *
     * Idempotent: a permit with an entry still open (not closed, not
     * cancelled) gets no second one, so the nightly scan can run any number
     * of times. A permit that is not Active is skipped — a suspended or
     * revoked certificate is not inspected for renewal of its standing, and a
     * business on hold has every permit suspended already. Nor one whose
     * business has no owner account: the entry needs an applicant to tell.
     *
     * @return int entries opened
     */
    public static function openDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $typeIds = PermitType::noExpiryIds();
        if ($typeIds === []) {
            return 0;
        }

        $due = Permit::query()
            ->whereIn('permit_type_id', $typeIds)
            ->where('status', PermitStatus::Active->value)
            ->whereDate('valid_until', '<=', $today->toDateString())
            ->whereHas('business', fn ($q) => $q->whereNotNull('owner_user_id'))
            ->with(['business', 'permitType'])
            ->get();

        $opened = 0;
        foreach ($due as $permit) {
            if (self::openFor($permit) !== null) {
                $opened++;
            }
        }

        return $opened;
    }

    /** The entry still open for this permit, if any. */
    public static function openEntryFor(Permit $permit): ?Application
    {
        return Application::query()
            ->where('application_type', ApplicationType::AnnualInspection->value)
            ->where('prior_permit_id', $permit->id)
            ->whereNotIn('status', [ApplicationStatus::Rejected->value, ApplicationStatus::Cancelled->value])
            ->notDecided()
            ->first();
    }

    /** Open one entry for this permit, or null when one is already open. */
    public static function openFor(Permit $permit): ?Application
    {
        if (self::openEntryFor($permit) !== null) {
            return null;
        }

        $workflow = app(WorkflowService::class);
        $type = $permit->permitType;

        $app = DB::transaction(function () use ($permit, $type, $workflow) {
            $app = Application::create([
                'tracking_id' => Numbering::trackingId(),
                'business_id' => $permit->business_id,
                'applicant_user_id' => $permit->business->owner_user_id,
                'application_type' => ApplicationType::AnnualInspection->value,
                'title' => 'Annual inspection',
                'status' => ApplicationStatus::ForApproval->value,
                // The permit being inspected. Read by `passed()`; the renewal
                // readers all filter on type, so none mistakes it for one.
                'prior_permit_id' => $permit->id,
                'submitted_at' => now(),
                // The day the inspection fell due, so the queue's Due column
                // reads it (and says Overdue once it has passed). Not an
                // RA 11032 clock: FilingClock reads filings only.
                'deadline_at' => $permit->valid_until?->copy()->endOfDay(),
            ]);

            $app->permitTypes()->attach($type->id, [
                'status' => ClearanceStatus::NotStarted->value,
                'mode' => 'apply',
                'submitted_at' => now(),
            ]);
            $row = $workflow->pivotFor($app, $type->code);
            // Through the regular transitions, so the permit's history and
            // the processing-time readers see the same steps as any other.
            $workflow->transitionClearance($row, ClearanceStatus::ForApproval, 'Yearly inspection is due.');
            $workflow->transitionClearance($row, ClearanceStatus::ForInspection, 'Opened for the yearly inspection.');
            $workflow->routeTo($app, $type->issuing_department_id);
            // Completed, as `approveClearance` leaves it once a permit reaches
            // its visit: the row then reads under For Inspection, not For Approval.
            $workflow->completeAssignment($app, $type->issuing_department_id, 'Yearly inspection is due.');

            Audit::log('annual_inspection.opened', $app, [
                'permit_id' => $permit->id,
                'permit_number' => $permit->permit_number,
                'due_on' => $permit->valid_until?->toDateString(),
            ]);

            return $app;
        });

        app(NotificationService::class)->applicationNote(
            $app,
            "Your {$type->name} is due for its yearly inspection. The office will set the date.",
        );

        return $app;
    }

    /**
     * A passing visit: the next inspection is a year from today.
     *
     * Called from `WorkflowService::grantClearance` in place of minting a
     * permit. The certificate stays the one already issued.
     */
    public static function passed(Application $app): ?Permit
    {
        $permit = $app->prior_permit_id ? Permit::find($app->prior_permit_id) : null;
        if ($permit === null) {
            return null;
        }

        $from = $permit->valid_until?->toDateString();
        $next = CarbonImmutable::now()->startOfDay()->addYear();
        $permit->update(['valid_until' => $next->toDateString()]);

        Audit::log('annual_inspection.passed', $permit, [
            'application_id' => $app->id,
            'from' => $from,
            'to' => $next->toDateString(),
        ]);

        return $permit;
    }
}
