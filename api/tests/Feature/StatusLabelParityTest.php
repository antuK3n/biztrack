<?php

use App\Enums\ApplicationStatus;

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
it('uses the wording the design specifies for the four states an admin tracks', function () {
    expect(ApplicationStatus::PendingPayment->label())->toBe('Pending Payment')
        ->and(ApplicationStatus::UnderReview->label())->toBe('For Approval')
        ->and(ApplicationStatus::ForInspection->label())->toBe('For Inspection')
        ->and(ApplicationStatus::Approved->label())->toBe('Approved')
        ->and(ApplicationStatus::Rejected->label())->toBe('Rejected');
});
