<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;

/*
 * The API and the web must call a status the same thing.
 *
 * They did not. `pending_payment` answered to "Awaiting payment" from the API,
 * "For payment" from the browser, and "Pending Payment" in the design the LGU
 * signed off — three names for one state, so an applicant on the phone and the
 * officer with the filing open were describing it differently. `under_review`
 * printed "Under review" on rows sitting inside a queue tab captioned "For
 * Approval", so one screen contradicted itself without anyone having to leave it.
 *
 * Nothing had made those two files disagree; nothing had stopped them either.
 * That is what this test is. The web keeps its own copy of the labels for real
 * reasons — it labels an `issued` status the API never sends on an application,
 * and a filter pill cannot wait on a round trip to caption itself — so the fix
 * is not to delete a copy, it is to make a copy that drifts fail the build.
 *
 * Edit a label on either side alone and this test names the exact status and the
 * exact two spellings. Add a case to the enum without a web entry and it says so.
 */

/** `web/src/lib/status.ts`, or null when the web workspace is not checked out. */
function statusTsSource(): ?string
{
    $path = base_path('../web/src/lib/status.ts');

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * The `APPLICATION_STATUS` object, as status value => label.
 *
 * Parsed rather than executed: standing up Node inside the PHP suite to read ten
 * string literals would buy nothing and would make this test skip on any machine
 * without a matching toolchain — which is precisely the machine where a drifting
 * label would then ship.
 *
 * @return array<string,string>
 */
function parsedWebStatusLabels(string $source): array
{
    $start = strpos($source, 'const APPLICATION_STATUS');
    expect($start)->not->toBeFalse('web/src/lib/status.ts no longer declares APPLICATION_STATUS; this test cannot see the labels it is guarding.');

    $body = substr($source, $start);
    $end = strpos($body, "\n}");
    $body = $end === false ? $body : substr($body, 0, $end);

    preg_match_all(
        "/^\s*(\w+):\s*\{\s*label:\s*'((?:[^'\\\\]|\\\\.)*)'/m",
        $body,
        $matches,
        PREG_SET_ORDER,
    );

    $labels = [];
    foreach ($matches as $match) {
        $labels[$match[1]] = stripslashes($match[2]);
    }

    return $labels;
}

it('gives every application status one label on both sides of the wire', function () {
    $source = statusTsSource();
    if ($source === null) {
        test()->markTestSkipped('web/src/lib/status.ts is not present in this checkout.');
    }

    $web = parsedWebStatusLabels($source);

    // A parse that quietly matched nothing would pass every assertion below.
    expect(count($web))->toBeGreaterThanOrEqual(count(ApplicationStatus::cases()));

    foreach (ApplicationStatus::cases() as $status) {
        expect(array_key_exists($status->value, $web))->toBeTrue(
            "web/src/lib/status.ts has no entry for '{$status->value}', so the browser will fall back to printing the raw enum value.",
        );

        expect($web[$status->value])->toBe(
            $status->label(),
            "Label drift on '{$status->value}': the API says \"{$status->label()}\", the web says \"{$web[$status->value]}\". Change both or neither.",
        );
    }
});

/*
 * The wording itself, pinned to the LGU's vocabulary (docs/rehaul-spec.md §4–5).
 *
 * The parity test above only proves the two sides agree — they agreed on the
 * wrong words for a long time on the officer queue. This one says what the words
 * are, so a future rename is a deliberate edit to a spec-backed expectation
 * rather than something that slides through because both files were touched.
 */
it('uses the wording the design specifies for the stages an admin tracks', function () {
    expect(ApplicationStatus::ForApproval->label())->toBe('For Approval')
        ->and(ApplicationStatus::PendingPayment->label())->toBe('Pending Payment')
        ->and(ApplicationStatus::AwaitingOtherPermits->label())->toBe('Awaiting Other Permits')
        ->and(ApplicationStatus::ForFinalApproval->label())->toBe('For Final Approval')
        ->and(ApplicationStatus::Approved->label())->toBe('Approved')
        ->and(ApplicationStatus::Rejected->label())->toBe('Rejected');
});

it('gives every CLEARANCE status one label on both sides of the wire', function () {
    /*
     * The second machine, held to the same rule as the first — and it was not,
     * until a label on it had to change.
     *
     * `ClearanceStatus::NotStarted` read "Not Started" in two files, and when
     * the client asked for "Not Yet Submitted" (9 September 2026, on the ground
     * that submitting is now its own act and "started" says nothing about
     * whether the office has it) there was nothing to stop one file being
     * edited and not the other. That is exactly the drift the application-status
     * test above exists to prevent; the clearance table simply never got one.
     *
     * `available` is skipped deliberately: it has no PHP counterpart. The API
     * emits it for an OPTIONAL permit with no pivot row at all, where there is
     * no status to label — so a missing entry there is correct rather than
     * drift.
     */
    $source = statusTsSource();
    if ($source === null) {
        test()->markTestSkipped('web/src/lib/status.ts is not present in this checkout.');
    }

    $start = strpos($source, 'const CLEARANCE_STATUS');
    expect($start)->not->toBeFalse('web/src/lib/status.ts no longer declares CLEARANCE_STATUS.');

    $body = substr($source, $start);
    $end = strpos($body, "\n}");
    $body = $end === false ? $body : substr($body, 0, $end);

    preg_match_all("/^\s*(\w+):\s*\{\s*label:\s*'((?:[^'\\\\]|\\\\.)*)'/m", $body, $matches, PREG_SET_ORDER);
    $web = [];
    foreach ($matches as $match) {
        $web[$match[1]] = stripslashes($match[2]);
    }

    expect(count($web))->toBeGreaterThanOrEqual(count(ClearanceStatus::cases()));

    foreach (ClearanceStatus::cases() as $status) {
        expect(array_key_exists($status->value, $web))->toBeTrue(
            "web/src/lib/status.ts has no entry for the clearance status '{$status->value}'.",
        );
        expect($web[$status->value])->toBe(
            $status->label(),
            "Label drift on clearance '{$status->value}': the API says \"{$status->label()}\", "
            ."the web says \"{$web[$status->value]}\". Change both or neither.",
        );
    }
});

/*
 * The officer's progress rail must be built out of statuses that still exist.
 *
 * The parity test above guards the LABEL TABLE, and it did its job — the table
 * was updated the day the flow changed. What nothing guarded was the SCREEN
 * that consumes it. `ApplicationProgress.tsx` went on asking for
 * `under_review` and `for_inspection` after both were deleted from this enum,
 * `applicationStatusMeta` found no entry, and its fallback printed the raw
 * request back out: an officer opening any filing read the words "under_review"
 * and "for_inspection" as the names of two of the four stages, under a rail
 * that also put payment before BPLO had approved anything.
 *
 * The rail is a literal array of enum VALUES with no server round trip to
 * correct it, which is exactly the shape that can go stale in silence. So it is
 * read here, from the same side of the wire as the labels, and a node naming a
 * status this enum no longer has fails the build.
 *
 * This does not police the ORDER — that is a product decision the LGU owns, and
 * a test asserting it would have to be edited by anyone reordering the process,
 * which is the kind of test people delete. It polices existence only.
 */
it('builds the officer progress rail out of statuses this enum still has', function () {
    $path = base_path('../web/src/components/ApplicationProgress.tsx');
    if (! is_file($path)) {
        test()->markTestSkipped('web/src/components/ApplicationProgress.tsx is not present in this checkout.');
    }

    $matched = preg_match(
        '/const RAIL: ApplicationStatus\[\] = \[(.*?)\]/s',
        (string) file_get_contents($path),
        $block,
    );
    expect($matched)->toBe(1, 'ApplicationProgress.tsx no longer declares `const RAIL: ApplicationStatus[]`; this test cannot see the rail it is guarding.');

    preg_match_all("/'([a-z_]+)'/", $block[1], $found);
    $rail = $found[1];

    // A parse that quietly matched nothing would pass the loop below.
    expect($rail)->not->toBeEmpty('the rail parsed as empty, so nothing was actually checked');

    $known = array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::cases());

    foreach ($rail as $step) {
        expect(in_array($step, $known, true))->toBeTrue(
            "The progress rail draws a '{$step}' step, which is not an ApplicationStatus any more. The browser will print that string, underscores and all, as the name of a stage.",
        );
    }
});
