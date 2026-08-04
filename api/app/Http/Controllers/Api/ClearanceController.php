<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\PermitType;
use App\Services\ClearanceService;
use App\Support\HeldPermits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The LGU clearance stage — the six supporting clearances, chosen before the
 * filing is submitted (docs/clearances-before-payment.md).
 *
 * Two ways to satisfy a clearance and they are not the same act:
 *   apply — attach the permit type. Its office's fee lines then appear on the
 *           one Tax Order of Payment assessed at submit, and its office is
 *           routed an assignment when that payment clears.
 *   held  — record the copy the business already holds. No permit type, so no
 *           form, no review and no fee. That asymmetry is the point.
 *
 * `meta` carries no money. It used to report a running balance, because a
 * clearance applied for after payment raised one; nothing here is chargeable
 * before submission and nothing here is open after it, so there is no balance
 * to report. `fee_preview` on each row still quotes what that clearance will
 * add to the assessment, which is the number the applicant actually needs.
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

        // The two routes are alternatives, and the screen renders one state per
        // card. Making the applicant take the copy back first is what keeps
        // "submitted" and "applied for" from both being true at once.
        abort_if(
            HeldPermits::find($application, $type) !== null,
            422,
            'You have already submitted a '.$type->name.' you hold. Remove that copy first if you want to apply for a new one.'
        );

        $this->clearances->apply($application, $type);

        return $this->rowResponse($application, $type);
    }

    /** DELETE {code}/apply — withdraw the request before the office acts. */
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

    /**
     * POST {code}/held — the copy the business already holds.
     *
     * Deliberately does NOT attach the permit type. Uploading here is the
     * applicant saying "I have this already", and the fee gating means that
     * escapes the charge — which is an open question, recorded rather than
     * quietly fixed: the Fire Code and sanitary inspection fees stay gated on
     * their clearance even though RA 9514 arguably charges them regardless.
     * Changing what a citizen is billed on our own reading of a statute is
     * BPLO's call, not this endpoint's.
     */
    public function storeHeld(Request $request, Application $application, string $code): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $type = $this->clearance($code);
        $this->assertUnlocked($application);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'file.required' => 'Choose the certificate file to upload.',
            'file.max' => 'The file may not be larger than 10MB.',
            'file.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ]);

        abort_if(
            $this->clearances->isAppliedFor($application, $type),
            422,
            'You have applied for the '.$type->name.' on this application. Withdraw that request first if you already hold one.'
        );

        HeldPermits::store($application, $type, $request->file('file'));

        return $this->rowResponse($application, $type, 201);
    }

    /** DELETE {code}/held — take the copy back off the filing. */
    public function destroyHeld(Request $request, Application $application, string $code): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $type = $this->clearance($code);
        $this->assertUnlocked($application);

        abort_if(
            HeldPermits::find($application, $type) === null,
            404,
            'No '.$type->name.' copy has been submitted on this application.'
        );

        HeldPermits::forget($application, $type);

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

    /** The stage is shut the moment the filing is submitted, and after. */
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
     * refused with a sentence the applicant can act on. (This guard also used
     * to be what stopped a 500: apply re-assessed inline. It no longer does —
     * the assessment happens at submit — so the reason is now the quote, not
     * the crash.) Reads are not gated by it: the stage still renders.
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
