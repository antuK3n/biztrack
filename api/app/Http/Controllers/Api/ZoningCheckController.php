<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\Zoning\ZoningCheck;
use App\Support\Zoning\ZoningContext;
use App\Support\Zoning\ZoningFacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * City Ordinance No. 24-2018, applied rule by rule — three doors onto one
 * evaluator (App\Support\Zoning\ZoningCheck).
 *
 *  - `preview` answers the applicant's wizard with what is on screen, saved or
 *    not, so the checklist follows them as they type. It writes nothing.
 *  - `show` answers a stored filing, for its owner and for any office that may
 *    read it — the same list the zoning officer decides against.
 *  - `officerFacts` is where the zoning officer records what only CPDO can
 *    determine (the lot's zone, a boundary across the lot, a special use) and
 *    may correct any applicant answer with a measured one.
 *
 * None of them refuses anything. The ordinance makes every land use a use by
 * right subject to review (Art. II §3(1)), and the review is CPDO's.
 */
class ZoningCheckController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'application_type' => ['nullable', 'in:new,renewal,amendment'],
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.psic_code_id' => ['nullable', 'integer', 'exists:psic_codes,id'],
            'lines.*.capitalization' => ['nullable', 'numeric', 'min:0'],
            'lines.*.gross_sales' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'floor_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lot_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'employees' => ['nullable', 'integer', 'min:0'],
            'capitalization' => ['nullable', 'numeric', 'min:0'],
            'street' => ['nullable', 'string', 'max:255'],
            'is_rented' => ['nullable', 'boolean'],
            'storeys' => ['nullable', 'integer', 'min:0', 'max:200'],
            ...ZoningFacts::rules('zoning_facts', ZoningFacts::applicantKeys()),
        ]);

        return response()->json(['data' => ZoningCheck::evaluate(ZoningContext::fromRequest($data))]);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        ApplicationVisibility::authorize($request->user(), $application, 'You may not view this application.');

        return response()->json(['data' => ZoningCheck::evaluate(ZoningContext::fromApplication($application))]);
    }

    /**
     * The zoning officer's own answers.
     *
     * Gated twice: `zoning.evaluate` on the route (CPDO, BPLO and the super
     * admin hold it), and the office boundary here — a zoning answer about a
     * filing the reader may not see is a leak, not a convenience. Allowed on
     * any status except a closed one, because an officer may need to record
     * the lot's zone before or after the sheet arrives, and recording it on a
     * finished filing would rewrite what the decision was made on.
     */
    public function officerFacts(Request $request, Application $application): JsonResponse
    {
        ApplicationVisibility::authorize($request->user(), $application, 'You may not view this application.');
        abort_if(
            in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Approved, ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true),
            422,
            'Zoning answers can be recorded only on a filing that is with the offices.',
        );

        $data = $request->validate(ZoningFacts::rules('facts', ZoningFacts::officerKeys()) + [
            'facts' => ['required', 'array'],
        ]);

        // A partial write: keys sent replace, keys sent as null clear, keys
        // not sent stay as they were.
        $current = is_array($application->zoning_officer_facts) ? $application->zoning_officer_facts : [];
        foreach ($data['facts'] as $key => $value) {
            if (! in_array($key, ZoningFacts::officerKeys(), true)) {
                continue;
            }
            if ($value === null || $value === '') {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
        $application->update(['zoning_officer_facts' => ZoningFacts::clean($current, ZoningFacts::officerKeys())]);
        Audit::log('application.zoning_facts_recorded', $application);

        return response()->json(['data' => ZoningCheck::evaluate(ZoningContext::fromApplication($application->fresh()))]);
    }
}
