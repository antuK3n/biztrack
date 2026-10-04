<?php

namespace App\Http\Controllers\Api\Debug;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Debug\FilingMover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Debug page's "Move a filing along" section (App\Services\Debug\FilingMover).
 *
 *   GET  debug/filings?q=           filings whose tracking ID matches, newest first
 *   GET  debug/filings/{id}         where it stands, what holds it, the legal steps
 *   POST debug/filings/{id}/steps   {step, permit?, note?}: one step
 *   POST debug/filings/{id}/advance {to}: the forward steps until `to` or a refusal
 *
 * Behind `debug.panel`, so nothing here checks a role. A refused step answers
 * 422 with the service's own sentence as `message`, and still carries the
 * result and the filing as it now stands, because the refusal is audited and
 * the page shows the filing either way. An advance answers 200 even when it
 * stops early: the steps before the refusal did happen.
 */
class FilingsController extends Controller
{
    public function __construct(private FilingMover $mover) {}

    public function index(Request $request): JsonResponse
    {
        $q = strtoupper(trim((string) $request->query('q', '')));

        /*
         * Drafts are left out: a draft has nothing for an office to press, and
         * listing every unsubmitted wizard would bury the filings that do.
         */
        $filings = Application::query()
            ->whereNotNull('tracking_id')
            ->where('status', '!=', ApplicationStatus::Draft->value)
            ->when($q !== '', fn ($query) => $query->where('tracking_id', 'like', '%'.$q.'%'))
            ->with('business')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return response()->json([
            'data' => $filings->map(fn (Application $app) => [
                'id' => $app->id,
                'tracking_id' => $app->tracking_id,
                'business' => $app->business?->name,
                'type_label' => $app->application_type?->label(),
                'status' => $app->status?->value,
                'status_label' => $app->status?->label(),
            ])->all(),
        ]);
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json(['data' => $this->mover->describe($application)]);
    }

    public function step(Request $request, Application $application): JsonResponse
    {
        $step = $request->input('step');

        $data = $request->validate([
            'step' => ['required', Rule::in(FilingMover::STEPS)],
            'permit' => [
                Rule::requiredIf(in_array($step, FilingMover::PERMIT_STEPS, true)),
                'nullable',
                'string',
                Rule::exists('permit_types', 'code'),
            ],
            'note' => [
                Rule::requiredIf(in_array($step, FilingMover::NOTE_REQUIRED, true)),
                'nullable',
                'string',
                'max:1000',
            ],
        ], [
            'permit.required' => 'Say which permit.',
            // The office screen's own words for a return with no remarks.
            'note.required' => 'Explain what the applicant needs to fix.',
        ]);

        $result = $this->mover->run(
            $application,
            $data['step'],
            $data['permit'] ?? null,
            $data['note'] ?? null,
            $request->user()->id,
        );

        $payload = ['result' => $result, 'filing' => $this->mover->describe($application)];

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['refusal'],
                'errors' => ['step' => [$result['refusal']]],
                'data' => $payload,
            ], 422);
        }

        return response()->json(['data' => $payload]);
    }

    public function advance(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(array_keys(FilingMover::TARGETS))],
        ], [
            'to.required' => 'Say which stage to advance to.',
            'to.in' => 'Say which stage to advance to.',
        ]);

        $outcome = $this->mover->advance($application, $data['to'], $request->user()->id);

        return response()->json([
            'data' => $outcome + ['filing' => $this->mover->describe($application)],
        ]);
    }
}
