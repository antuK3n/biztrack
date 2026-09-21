<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Support\AmendableFields;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an amendment asks to change: read it, state it, take it back.
 *
 * ── Why this is not part of `ApplicationController::update` ────────────────
 *
 * Same argument that gave `PriorPermitController` its own file. The requested
 * changes are not fields OF the application — they are a set of rows about a
 * different record entirely, they are editable on their own schedule while the
 * draft is open, and each one can be withdrawn without touching the rest. An
 * `update` that carried them would have to accept a whole set on every autosave
 * and decide what an absent key meant, which is how a half-typed form silently
 * deletes a change the applicant made a minute ago.
 *
 * ── The whitelist is enforced here, at the door ────────────────────────────
 *
 * Client, 19 September 2026: *"only business permit details can be amended."*
 * A field outside `AmendableFields` is REFUSED BY NAME rather than dropped,
 * because an amendment that quietly discards one of five requested changes is
 * worse than one that says no: the applicant leaves believing they asked.
 */
class AmendmentController extends Controller
{
    /** Every amendable detail, with what the business holds and what was asked. */
    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $requested = $application->requestedChanges()->get()->keyBy('field');
        $business = $application->business;

        $rows = [];
        foreach (AmendableFields::kinds() as $field => $spec) {
            $row = $requested->get($field);
            $group = AmendableFields::GROUPS[$spec['group']];

            /*
             * The register's value, sent alongside every row whether or not a
             * change was asked for. The screen is "what it is now, what you
             * want it to be", and a form that shows only the new value asks
             * somebody to remember what they are replacing.
             */
            $current = AmendableFields::current($business, $field);

            $rows[] = [
                'field' => $field,
                /*
                 * Which of FO-003's four checkboxes this came off, so the step
                 * can print them as the paper prints them and the applicant
                 * holding the form can follow along. The numeral is the
                 * paper's own (I, II, III), null for the unnumbered top box.
                 */
                'group' => $spec['group'],
                'group_label' => $group['label'],
                'group_paper' => $group['paper'],
                'label' => $spec['label'],
                'help' => $spec['help'],
                /*
                 * What control to draw. Half of these stopped being free text
                 * when the paper's boxes were mapped properly: a line of
                 * business is a PSIC code, a barangay is a list zoning is
                 * assessed against, a pin is a map.
                 */
                'type' => $spec['type'],
                'current_value' => $current,
                /*
                 * An id is not a thing to show anybody. Resolved here rather
                 * than in the client, because the client would need the whole
                 * PSIC table loaded to print one row.
                 */
                'current_label' => AmendableFields::describe($field, $current),
                'new_value' => $row?->new_value,
                'new_label' => AmendableFields::describe($field, $row?->new_value),
                'requested' => $row !== null,
                /*
                 * Null until BPLO completes it. Once stamped, the pair
                 * (old_value, applied_at) is the record of what this amendment
                 * actually did — which the applicant can read back later.
                 */
                'old_value' => $row?->old_value,
                'applied_at' => $row?->applied_at?->toISOString(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * State the new value for one detail, or several at once.
     *
     * `updateOrCreate` on (application, field), matching the unique index: one
     * request per field per filing. Asking twice for the same field is a change
     * of mind, not two requests, so the second replaces the first.
     */
    public function store(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $this->requireEditableAmendment($application);

        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.field' => ['required', 'string'],
            'changes.*.new_value' => ['present', 'nullable'],
        ]);

        /*
         * Refused as a whole, before anything is written. A request naming four
         * good fields and one bad one is a caller with a bug, and applying four
         * fifths of it would leave the applicant to discover which fifth went
         * missing.
         */
        $unknown = collect($data['changes'])
            ->pluck('field')
            ->reject(fn ($field) => AmendableFields::allows($field))
            ->unique()
            ->values();

        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'changes' => [
                    /*
                     * The sentence used to name address, ownership and line of
                     * business as the things this form could not do. All three
                     * are on FO-003 and all three are built now, so it named
                     * the wrong boundary — it would have sent an applicant to
                     * the window for a change the screen behind them offers.
                     *
                     * Named by BOX rather than by field. Listing all eighteen
                     * labels was accurate and 467 characters long, which is a
                     * paragraph where an error message should be a sentence.
                     * The four boxes are what the paper offers and what the
                     * screen is laid out as, so they are what a reader can act
                     * on.
                     */
                    'These details cannot be amended through BizTrack: '.$unknown->join(', ')
                    .'. The Amendment Form covers '
                    .collect(AmendableFields::GROUPS)
                        ->pluck('label')
                        ->map(fn (string $l) => lcfirst($l))
                        ->join(', ', ' and ')
                    .'.',
                ],
            ]);
        }

        /*
         * Validated per field, with that field's own rules. A floor area and an
         * employee count are both "a number" and are not the same number: one
         * is a decimal and one is not, and a shared rule would accept 12.5
         * employees.
         */
        foreach ($data['changes'] as $i => $change) {
            $rules = AmendableFields::kinds()[$change['field']]['validation'];
            validator(
                ['value' => $change['new_value']],
                ['value' => $rules],
                [],
                ['value' => AmendableFields::label($change['field'])],
            )->validate();
        }

        DB::transaction(function () use ($application, $data) {
            foreach ($data['changes'] as $change) {
                $application->requestedChanges()->updateOrCreate(
                    ['field' => $change['field']],
                    [
                        'new_value' => $change['new_value'] === null
                            ? null
                            : (string) $change['new_value'],
                    ],
                );
            }
        });

        Audit::log('amendment.requested', $application, [
            'fields' => collect($data['changes'])->pluck('field')->all(),
        ]);

        return $this->index($request, $application->fresh());
    }

    /** Withdraw one requested change. */
    public function destroy(Request $request, Application $application, string $field): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $this->requireEditableAmendment($application);

        $application->requestedChanges()->where('field', $field)->delete();

        return $this->index($request, $application->fresh());
    }

    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
    }

    /**
     * Editable while the applicant still holds it.
     *
     * `Returned` as well as `Draft`, matching `destroyDocument`'s allow-list and
     * for the same reason: being sent back to fix something is the one moment
     * changing it matters most. Past that, the amendment has been priced and
     * read, and a requested change edited after the bill would bill for one
     * thing and apply another.
     */
    private function requireEditableAmendment(Application $application): void
    {
        abort_unless(
            $application->application_type === ApplicationType::Amendment,
            422,
            'Only an amendment can carry requested changes.'
        );

        abort_unless(
            in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true),
            422,
            'This amendment has been submitted, so its requested changes can no longer be edited.'
        );
    }
}
