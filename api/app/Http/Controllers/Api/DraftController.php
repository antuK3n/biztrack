<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WizardDraft;
use App\Support\Audit;
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
class DraftController extends Controller
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
            'title' => self::freeTitle($request->user()->id, $data['title'] ?? null),
            'payload' => $data['payload'],
        ]);

        return response()->json(['data' => $this->summary($draft)], 201);
    }

    /**
     * "New Business Permit", then "New Business Permit (2)", and so on.
     *
     * ── Why the number is STORED and not computed when the list draws ────
     *
     * The Drafts page used to count repeats at render time. That kept the
     * numbers tidy and made the wizard disagree with the list: the title
     * box held "New Business Permit" while the card beside it said
     * "New Business Permit (8)", and the applicant had no way to tell
     * which name was really theirs (client, 2 October 2026).
     *
     * Storing it settles that, and the client named the model: Google
     * Drive. A name given at creation is the file's own from then on. It
     * is searchable, the office sees the same words the applicant does,
     * and it follows the filing into the record — none of which is true of
     * a number that exists only while one screen is open.
     *
     * The accepted cost, stated because it will be noticed: numbers do not
     * close up. Delete (3) and the list runs 1, 2, 4 — exactly as every
     * file manager behaves, and the alternative is renaming a draft
     * somebody has been calling (8) while they are looking at it.
     *
     * ── Why the SERVER picks it ──────────────────────────────────────────
     *
     * Only it can. The browser would have to fetch every sibling name to
     * choose, and two tabs opened together would still both read "(8)" as
     * free and both take it. Here the names are one query away and the
     * choice is made in the same request that writes the row.
     *
     * Scoped to the one owner: two businesses may each hold a draft called
     * "New Business Permit" and neither is a repeat of the other.
     */
    private static function freeTitle(int $userId, ?string $wanted): ?string
    {
        $base = trim((string) $wanted);

        // No name offered is not a clash to resolve — the row keeps its
        // null and the card falls back to its own wording.
        if ($base === '') {
            return null;
        }

        $taken = WizardDraft::where('user_id', $userId)
            ->whereNotNull('title')
            ->pluck('title')
            ->map(fn ($t) => trim((string) $t))
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        /*
         * From (2), because the FIRST keeps the bare name. That is the
         * convention the Drafts page already followed and the one a reader
         * expects: a person with a single draft never sees a number at all.
         *
         * Bounded rather than `while (true)`: a loop over a list this
         * caller controls should not be the thing that hangs a request. At
         * the ceiling it falls through to the bare name and lets the two
         * sit together, which is untidy and not wrong.
         */
        for ($n = 2; $n <= 999; $n++) {
            $candidate = $base.' ('.$n.')';
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return $base;
    }

    /** One unfinished filing, with its answers. */
    public function show(Request $request, int $wizardDraft): JsonResponse
    {
        $draft = $this->ownedOrFail($request, $wizardDraft);

        /*
         * This endpoint is only ever called to resume one, so reaching it
         * IS opening it. `saveQuietly` and `timestamps = false` keep
         * `updated_at` for saves alone — otherwise the two dates on the
         * card would always be the same and the sort would mean nothing.
         */
        $draft->timestamps = false;
        $draft->forceFill(['last_opened_at' => now()])->saveQuietly();
        $draft->timestamps = true;

        return response()->json(['data' => [
            ...$this->summary($draft),
            'payload' => $draft->payload,
        ]]);
    }

    /** Replace its answers. */
    /**
     * Replace its answers, its title, or just the title.
     *
     * The wizard sends both on every save. The drafts list renames one and
     * knows nothing about the payload — so `payload` is optional here, and
     * omitting it leaves the answers alone. Requiring it would make a
     * rename resend a form the caller never loaded, and getting that wrong
     * writes a stale copy over the applicant's work.
     */
    public function update(Request $request, int $wizardDraft): JsonResponse
    {
        $draft = $this->ownedOrFail($request, $wizardDraft);
        $data = $request->validate($this->payloadRules(requirePayload: false));

        $changes = [];

        if (array_key_exists('payload', $data)) {
            if ($tooBig = $this->refuseIfHuge($data['payload'])) {
                return $tooBig;
            }
            $changes['payload'] = $data['payload'];
        }

        /*
         * `array_key_exists`, not `??`: a caller that sends `title: null`
         * is clearing the name, and one that omits the key is not talking
         * about the name at all. Collapsing those would have every payload
         * save silently wipe a title the applicant chose.
         */
        if (array_key_exists('title', $data)) {
            $changes['title'] = $data['title'];
        }

        if ($changes !== []) {
            $draft->update($changes);
        }

        return response()->json(['data' => $this->summary($draft)]);
    }

    /**
     * Throw it away.
     *
     * Idempotent for a row that has already gone: the wizard deletes one the
     * moment a real draft exists, and must not fail if a second tab got there
     * first.
     *
     * ── Kept in the audit log, like an application draft ────────────────
     *
     * Deleting an application draft has kept a full copy under the audit
     * log's "Removed" view since Audit Log 1; deleting one of these from the
     * same card, behind the same dialog, left no trace at all (checklist,
     * Audit Log 1, re-check). So the applicant's delete is now
     * `wizard_draft.deleted` through Audit::removed(), answers and all.
     *
     * `superseded` is the wizard's own clean-up once a real draft exists:
     * nothing is lost, the answers live on in the application draft. That is
     * logged as what it is, with no copy, so the Removed view lists only the
     * drafts somebody actually threw away.
     */
    public function destroy(Request $request, int $wizardDraft): JsonResponse
    {
        $draft = WizardDraft::where('user_id', $request->user()->id)->find($wizardDraft);

        if ($draft !== null) {
            $changes = [
                'application_type' => $draft->application_type,
                'title' => $draft->title,
            ];

            // Logged BEFORE the delete, while the row still says what it said.
            $request->boolean('superseded')
                ? Audit::log('wizard_draft.superseded', $draft, $changes)
                : Audit::removed('wizard_draft.deleted', $draft, $changes);

            $draft->delete();
        }

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
    private function payloadRules(bool $requirePayload = true): array
    {
        return [
            'payload' => [$requirePayload ? 'required' : 'sometimes', 'array'],
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
            /* When it was last RESUMED, which is a different fact. */
            'last_opened_at' => optional($draft->last_opened_at)->toISOString(),
        ];
    }
}
