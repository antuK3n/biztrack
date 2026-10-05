<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\PermitType;
use App\Services\ClearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The LGU clearance stage — the six supporting clearances, applied for AFTER
 * the business permit has been paid for (docs/clearances-after-payment.md).
 *
 * Two ways to satisfy a clearance and they are not the same act:
 *   apply — attach the permit type. The filing is re-assessed on the spot, so
 *           that office's fee lines join the balance, and the office is routed
 *           an assignment at that moment.
 *   held  — record the copy the business already holds. No permit type, so no
 *           form, no review and no fee. That asymmetry is the point.
 *
 * `meta` carries the ledger — `total_assessed`, `total_paid`, `balance_due` —
 * because applying raises a debt in the same request that raises it, and no
 * permit is released until it reaches zero. `fee_preview` on each row quotes
 * what pressing Apply would add, before it is pressed.
 *
 * Owner-only, all four writes and the read. An office reviewing a clearance
 * sees it through the assignment it was routed; nobody but the applicant
 * decides which clearances a filing asks for.
 */
class ClearanceController extends Controller
{
    public function __construct(private ClearanceService $clearances) {}

    /** GET — the six cards plus whether the stage is still open to change. */
    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $overview = $this->clearances->overview($application);

        return response()->json([
            'data' => $overview['rows'],
            'meta' => $overview['meta'],
        ]);
    }

    /** POST {code}/apply — ask this office for the clearance. */
    public function apply(Request $request, Application $application, string $code): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $type = $this->clearance($code);
        $this->assertUnlocked($application);
        $this->assertPriceable($application);

        abort_if(
            $this->clearances->isAppliedFor($application, $type),
            422,
            'You have already applied for the '.$type->name.' on this application.'
        );

        /*
         * A new business's other permits are the ones BPLO ticked (client,
         * 5 October 2026: "BPLO decides, no rules"). `startClearance` attaches
         * a type the filing lacks, which would let an applicant add an
         * unticked clearance — unbilled — and route an office to it.
         */
        abort_if(
            $application->application_type === ApplicationType::New
                && $type->isRequiredClearance()
                && ! $application->permitTypes()->where('permit_types.id', $type->id)->exists(),
            422,
            'BPLO did not list the '.$type->name.' for this business.'
        );


        $this->clearances->apply($application, $type);

        return $this->rowResponse($application, $type);
    }

    /**
     * DELETE {code}/apply — withdraw the request before the office acts.
     *
     * The only thing in the system that detaches a permit type from a filing,
     * and therefore the only way out of `apply`. For four days it had no caller
     * on any screen while the held-copy endpoint went on telling applicants to
     * use it (CLR-1): 15 real drafts could not withdraw a clearance, 5 of them
     * could not be submitted at all because applying spawns a mandatory office
     * sheet, and the one route out was to destroy the whole filing.
     *
     * Its caller is the Withdraw control on the clearance card. There were two
     * until 4 October 2026 — the other was the Submit dialog, which withdrew
     * and then uploaded a copy the applicant held, and uploads are gone. The
     * guards below stay: `officeHasActed` in particular was written as defence
     * in depth when nothing could reach it, and that is still its value.
     */
    public function unapply(Request $request, Application $application, string $code): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $type = $this->clearance($code);
        $this->assertUnlocked($application);
        // Un-applying re-assesses too, so it needs a priceable filing just as
        // much as applying does.
        $this->assertPriceable($application);

        abort_unless(
            $this->clearances->isAppliedFor($application, $type),
            422,
            'You have not applied for the '.$type->name.' on this application.'
        );

        abort_if(
            $application->permits()->where('permit_type_id', $type->id)->exists(),
            422,
            'The '.$type->name.' has already been issued, so the application for it can’t be withdrawn.'
        );

        abort_if(
            $this->clearances->officeHasActed($application, $type),
            422,
            'The '.($type->department?->name ?? 'issuing office')
            .' has already started on your '.$type->name.', so it can’t be withdrawn here. Message the office if you no longer need it.'
        );

        $this->clearances->unapply($application, $type);

        return $this->rowResponse($application, $type);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * The clearance named in the URL.
     *
     * A 404 for anything that is not one of the six, including BUSINESS: the
     * mayor's permit is the outcome of the application, not a clearance to pick
     * up or put down, so there is no such resource at this address.
     */
    private function clearance(string $code): PermitType
    {
        $type = $this->clearances->findClearance($code);

        abort_if($type === null, 404, 'There is no LGU clearance with that code.');

        return $type;
    }

    /** The stage is shut until the first payment clears. */
    private function assertUnlocked(Application $application): void
    {
        abort_unless(
            $this->clearances->isUnlocked($application),
            422,
            $this->clearances->lockedReason($application) ?? 'The LGU clearances are not open on this application yet.'
        );
    }

    /**
     * Choosing a clearance needs a business to price it against.
     *
     * 139 filings in the register point at a soft-deleted business, and
     * FeeCalculator::assess dereferences `business->lines` without a guard — so
     * a card on one of those filings shows a null `fee_preview` rather than a
     * price. Applying is then agreeing to a charge nobody can quote, and it is
     * refused with a sentence the applicant can act on.
     *
     * It stops a 500 as well as an unquoted charge, and that is back: apply and
     * unapply both re-assess inline now, so reaching FeeCalculator with no
     * business record is a fatal, not a missing figure. Two reasons for one
     * guard, and either alone would justify it. Reads are not gated by it: the
     * stage still renders.
     */
    private function assertPriceable(Application $application): void
    {
        abort_if(
            $application->business === null,
            422,
            'This application’s business record has been removed from the register, so its fees can’t be re-assessed. Contact the BPLO.'
        );
    }

    /**
     * Every write answers with the card it changed. `meta` is the same shape
     * the index returns so the screen has one parser for both.
     */
    private function rowResponse(Application $application, PermitType $type, int $status = 200): JsonResponse
    {
        // Re-read so the row reflects what the write just did rather than the
        // relations loaded before it. `?? $application` because fresh() answers
        // null for a row that has since gone, and a 500 on the way out would
        // hide a write that actually succeeded.
        $fresh = $application->fresh() ?? $application;

        return response()->json([
            'data' => $this->clearances->row($fresh, $type),
            'meta' => $this->clearances->meta($fresh),
        ], $status);
    }

    /**
     * Only the owning applicant, on read as well as on write.
     *
     * Same rule and the same sentence as ApplicationController::authorizeOwner.
     * Not ApplicationVisibility: that answers "may this office read the
     * filing", which is a wider question than this one. Which clearances a
     * business asks for is the applicant's decision, and an office with no part
     * in it has no reason to see the chooser.
     */
    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
    }
}
