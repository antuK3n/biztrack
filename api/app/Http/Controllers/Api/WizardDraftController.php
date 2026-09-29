<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WizardDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The wizard's resume point, for answers that cannot be a draft yet.
 *
 * ── Scope ───────────────────────────────────────────────────────────────────
 *
 * Every action looks a row up WITHIN the caller's own rows — `where user_id`
 * before the key — so a row belonging to somebody else is not forbidden, it
 * simply is not found, and the caller gets the same 404 as for an id that
 * never existed. That is the whole authorisation story, and it is worth being
 * this short: the payload is a half-finished application form carrying a
 * name, an address and a TIN.
 *
 * ── One row per START ───────────────────────────────────────────────────────
 *
 * Addressed by id. The key was (user, application_type) until 29 September
 * 2026, which meant one unfinished New Business Permit per person — so
 * starting a second one either reopened the first or overwrote it, and the
 * client hit both in turn. These are drafts; a person may have as many as
 * they like.
 *
 * ── What it does not do ─────────────────────────────────────────────────────
 *
 * It does not validate the form. These are answers in progress, most of them
 * incomplete by definition, and the only screen that reads them back is the
 * one that wrote them. Nor does it interpret the payload: the route is exempt
 * from `ConvertEmptyStringsToNull` and `TrimStrings` (see bootstrap/app.php)
 * so what comes back is what was sent, character for character.
 *
 * The real rules live where they already live — `BusinessController` and
 * `ApplicationController` — and nothing here shortens them. A scratch row can
 * never become a filing; the wizard creates a proper draft through those
 * endpoints and then deletes this.
 */
class WizardDraftController extends Controller
{
    /** Every unfinished filing this applicant has, newest first. */
    public function index(Request $request): JsonResponse
    {
        $drafts = WizardDraft::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $drafts->map(fn (WizardDraft $d) => $this->summary($d))->all(),
        ]);
    }

    /**
     * Begin one.
     *
     * Called on the first change the wizard has to save, not when the form is
     * opened — see the note on `openedSnapshotRef` there. Opening a blank form
     * and leaving must not leave a row behind.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application_type' => ['required', 'string', 'max:32'],
            ...$this->payloadRules(),
        ]);

        if ($tooBig = $this->refuseIfHuge($data['payload'])) {
            return $tooBig;
        }

        $draft = WizardDraft::create([
            'user_id' => $request->user()->id,
            'application_type' => $data['application_type'],
            'title' => $data['title'] ?? null,
            'payload' => $data['payload'],
        ]);

        return response()->json(['data' => $this->summary($draft)], 201);
    }

    /** One unfinished filing, with its answers. */
    public function show(Request $request, int $wizardDraft): JsonResponse
    {
        $draft = $this->ownedOrFail($request, $wizardDraft);

        return response()->json(['data' => [
            ...$this->summary($draft),
            'payload' => $draft->payload,
        ]]);
    }

    /** Replace its answers. */
    public function update(Request $request, int $wizardDraft): JsonResponse
    {
        $draft = $this->ownedOrFail($request, $wizardDraft);
        $data = $request->validate($this->payloadRules());

        if ($tooBig = $this->refuseIfHuge($data['payload'])) {
            return $tooBig;
        }

        $draft->update([
            'title' => $data['title'] ?? null,
            'payload' => $data['payload'],
        ]);

        return response()->json(['data' => $this->summary($draft)]);
    }

    /**
     * Throw it away.
     *
     * Idempotent for a row that has already gone: the wizard deletes one the
     * moment a real draft exists, and must not fail if a second tab got there
     * first.
     */
    public function destroy(Request $request, int $wizardDraft): JsonResponse
    {
        WizardDraft::where('user_id', $request->user()->id)
            ->whereKey($wizardDraft)
            ->delete();

        return response()->json(null, 204);
    }

    /**
     * The shape is checked, the contents are not — see the note above.
     *
     * `array` keeps a string or a number out of a json column. Nothing else is
     * asserted about it, because every field in there is allowed to be wrong.
     *
     * @return array<string, list<string>>
     */
    private function payloadRules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A runaway payload is refused rather than written.
     *
     * This is saved on a debounce, so an oversized document would be written
     * again on every keystroke batch for as long as the form stayed open.
     */
    private function refuseIfHuge(array $payload): ?JsonResponse
    {
        if (strlen((string) json_encode($payload)) <= 256_000) {
            return null;
        }

        return response()->json([
            'message' => 'These answers are too large to save automatically.',
        ], 422);
    }

    /** Within the caller's own rows, so somebody else's id is simply not found. */
    private function ownedOrFail(Request $request, int $id): WizardDraft
    {
        return WizardDraft::where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    /** @return array{id: int, application_type: string, title: string|null, updated_at: string|null} */
    private function summary(WizardDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'application_type' => $draft->application_type,
            'title' => $draft->title,
            'updated_at' => optional($draft->updated_at)->toISOString(),
        ];
    }
}
