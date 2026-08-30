<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermitResource;
use App\Models\Application;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which permit a renewal or amendment is FOR (checklist item 50).
 *
 * Renewal used to pick a business and carry whatever it happened to hold. A
 * business can hold half a dozen clearances with different expiry dates, so
 * "renew my business" is not an instruction anyone can act on — the applicant
 * has to name the permit, exactly as the official LGU sites make them.
 *
 * The column (applications.prior_permit_id) already existed; it just had no
 * way of being read back or changed after the draft was created.
 */
class PriorPermitController extends Controller
{
    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $application->loadMissing('priorPermit.permitType', 'priorPermit.business', 'priorPermit.application');

        return response()->json([
            'data' => [
                'prior_permit_id' => $application->prior_permit_id,
                'prior_permit' => $application->priorPermit
                    ? new PermitResource($application->priorPermit)
                    : null,
                // Null and "declared none" are different answers and the wizard
                // has to be able to tell them apart when it reopens a draft:
                // one restores a ticked escape, the other an open question.
                'declared_none' => (bool) $application->prior_permit_declared_none,
            ],
        ]);
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        abort_unless(
            $application->status === ApplicationStatus::Draft,
            422,
            'Only draft applications can be edited.'
        );

        $data = $request->validate([
            // Null is a real answer: "none of these", for a business whose
            // paper permits predate the system. It is only a real answer when
            // it arrives with the flag below — see the comment there.
            'prior_permit_id' => ['present', 'nullable', 'exists:permits,id'],
            'declared_none' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['prior_permit_id'])) {
            $belongs = $application->business
                && $application->business->permits()->whereKey($data['prior_permit_id'])->exists();
            abort_unless($belongs, 422, 'The selected prior permit does not belong to this business.');
        }

        /*
         * Naming a permit and declaring there is none are contradictory
         * answers, so the named permit wins and the flag is cleared rather
         * than both being stored. An applicant who ticked the escape and then
         * found their permit in the list has changed their mind, not made two
         * statements — and a row holding both would make the submit gate below
         * pass for the wrong reason.
         */
        $priorPermitId = $data['prior_permit_id'] ?? null;

        $application->update([
            'prior_permit_id' => $priorPermitId,
            'prior_permit_declared_none' => $priorPermitId === null
                && (bool) ($data['declared_none'] ?? false),
        ]);

        Audit::log('application.prior_permit_set', $application);

        return $this->show($request, $application->refresh());
    }

    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
    }
}
