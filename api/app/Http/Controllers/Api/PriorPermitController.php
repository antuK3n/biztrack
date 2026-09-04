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
        $application->loadMissing(
            'priorPermit.permitType',
            'priorPermit.business',
            'priorPermit.application',
            'priorPermits.permitType',
        );

        return response()->json([
            'data' => [
                'prior_permit_id' => $application->prior_permit_id,
                'prior_permit' => $application->priorPermit
                    ? new PermitResource($application->priorPermit)
                    : null,
                /*
                 * The whole set, so a reopened draft restores every tick the
                 * applicant made rather than just the primary. Ids alone: the
                 * dialog re-fetches the permits themselves from the prefill it
                 * already asks for, and sending them twice would give it two
                 * copies to disagree about.
                 */
                'prior_permit_ids' => $application->priorPermits->pluck('id')->all(),
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
            /*
             * The rest of the set. A renewal covers every permit the shop
             * holds, not one of them — see the pivot migration. The primary
             * above still keys the renewal chain; this carries the full answer
             * the dialog was given, and an absent key leaves the set alone so a
             * caller that only wants to move the escape flag can still do so.
             */
            'prior_permit_ids' => ['sometimes', 'array'],
            'prior_permit_ids.*' => ['exists:permits,id'],
            'declared_none' => ['sometimes', 'boolean'],
        ]);

        $ids = array_map('intval', (array) ($data['prior_permit_ids'] ?? []));
        if (! empty($data['prior_permit_id'])) {
            array_unshift($ids, (int) $data['prior_permit_id']);
        }
        $ids = array_values(array_unique(array_filter($ids)));

        /*
         * Every permit named must belong to this business — the whole set, not
         * just the primary. Office separability is a boundary: a filing that
         * names a permit it has no claim to is how a reader ends up looking at
         * a business it may not see.
         */
        if ($ids !== []) {
            $owned = $application->business
                ? $application->business->permits()->whereKey($ids)->count()
                : 0;
            abort_unless($owned === count($ids), 422, 'A selected prior permit does not belong to this business.');
        }

        /*
         * Naming a permit and declaring there is none are contradictory
         * answers, so the named permit wins and the flag is cleared rather
         * than both being stored. An applicant who ticked the escape and then
         * found their permit in the list has changed their mind, not made two
         * statements — and a row holding both would make the submit gate below
         * pass for the wrong reason.
         */
        $priorPermitId = $ids[0] ?? null;

        $application->update([
            'prior_permit_id' => $priorPermitId,
            'prior_permit_declared_none' => $priorPermitId === null
                && (bool) ($data['declared_none'] ?? false),
        ]);

        /*
         * Only when the caller actually sent a set. `sync([])` on an absent key
         * would silently empty the pivot for every existing caller that still
         * sends the primary alone — which is every draft saved before the
         * dialog became multi-select.
         */
        if (array_key_exists('prior_permit_ids', $data) || $priorPermitId !== null) {
            $application->priorPermits()->sync($ids);
        }

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
