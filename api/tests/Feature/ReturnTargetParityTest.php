<?php

use App\Support\ReturnTargets;

/*
 * The API and the web must agree on which returnable fields are single boxes,
 * and on which column each one writes.
 *
 * `web/src/lib/returnTargets.ts` owns the list an officer picks from — the
 * labels, the grouping, the order the applicant is asked. `ReturnTargets` on
 * this side owns the only two facts the corrections endpoint needs: is this
 * code a SCALAR, and what column does it write.
 *
 * Two copies of one list, for the reason `StatusLabelParityTest` states about
 * the status labels: the browser cannot wait on a round trip to caption a
 * dropdown, and this side cannot take a column name out of a request body. So
 * the answer is not to delete a copy, it is to make a copy that drifts fail the
 * build.
 *
 * The specific failure this prevents: somebody adds a field to the wizard and
 * to the TypeScript picker, an officer ticks it on a return, and the applicant
 * is shown a correction box whose answer the API silently refuses to write —
 * because this map never heard of it. The applicant would resubmit, in good
 * faith, having changed nothing.
 */

/** `web/src/lib/returnTargets.ts`, or null when the web workspace is absent. */
function returnTargetsTsSource(): ?string
{
    $path = base_path('../web/src/lib/returnTargets.ts');

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * Every row of MAIN_FORM_RETURN_TARGETS, as code => ['kind' => ..., 'field' => ...].
 *
 * Parsed rather than executed, on `StatusLabelParityTest`'s reasoning: standing
 * up Node inside the PHP suite to read thirty object literals would make this
 * test SKIP on a machine without a matching toolchain — which is exactly the
 * machine where the drift would then ship.
 *
 * @return array<string, array{kind: string}>
 */
function parsedWebReturnTargets(string $source): array
{
    /*
     * Each row first, then its keys — NOT one regex spanning the whole row.
     *
     * The first version of this matched `value` … `kind` … optional `field` …
     * `}` in a single expression, which quietly assumed nothing else ever sits
     * between those keys. On 27 September 2026 `phase` did, the trailing `\}`
     * stopped matching, and the pattern BACKTRACKED ACROSS THE ROW BOUNDARY —
     * pairing `form:owner_name` with the next row's kind and column. Nine rows
     * vanished and one was silently given another field's destination, which
     * for a correction endpoint means writing an answer into the wrong column.
     *
     * Caught by this file's own count assertion rather than by anything
     * downstream, which is the whole reason that assertion is here. Splitting
     * the parse means a key added in any position is simply not read, instead
     * of corrupting its neighbours.
     */
    preg_match_all("/\{[^{}]*\bvalue:\s*'[^']+'[^{}]*\}/", $source, $rows);

    $out = [];
    foreach ($rows[0] as $row) {
        if (! preg_match("/\bvalue:\s*'([^']+)'/", $row, $v)) {
            continue;
        }
        if (! preg_match("/\bkind:\s*'(scalar|section)'/", $row, $k)) {
            continue;
        }
        $out[$v[1]] = ['kind' => $k[1]];
    }

    return $out;
}

it('parses the web return-target list at all', function () {
    $source = returnTargetsTsSource();
    if ($source === null) {
        test()->markTestSkipped('web/ is not checked out beside api/.');
    }

    /*
     * A guard on the PARSER, not on the data. If the TypeScript is reformatted
     * so the regex above stops matching, every assertion below would pass
     * against an empty array and this test would go quietly green while
     * protecting nothing — the failure mode a parity test must not have.
     */
    /*
     * 32 since 29 September 2026, when `form:documents` left: Section C's
     * requirements are now assembled per filing in ReviewPage from the
     * payload, because they are conditional and a fixed list would offer a
     * new filing a renewal document nobody asked it for. They are therefore
     * not in this file and not counted here.
     *
     * 33 before that: items 10-13 became four targets instead of
     * one lumped "Owner / Representative", the emergency contact became its
     * two boxes, and `form:lessor` went because the wizard no longer collects
     * a lessor block. The exact figure is not the point — a number that must
     * be updated deliberately is, because the alternative is a reformat
     * silently reducing this to nothing and every assertion below passing
     * against an empty array.
     */
    expect(parsedWebReturnTargets($source))->toHaveCount(32);
});

it('agrees with the web on which targets are single fields', function () {
    $source = returnTargetsTsSource();
    if ($source === null) {
        test()->markTestSkipped('web/ is not checked out beside api/.');
    }

    $web = parsedWebReturnTargets($source);
    $webScalars = array_keys(array_filter($web, fn ($t) => $t['kind'] === 'scalar'));
    $apiScalars = array_keys(ReturnTargets::SCALAR_FIELDS);

    sort($webScalars);
    sort($apiScalars);

    expect($apiScalars)->toBe($webScalars);
});

/*
 * ── The column comparison used to live here, and it was worse than useless ──
 *
 * It asserted that `ReturnTargets::SCALAR_FIELDS` named the same column as the
 * TypeScript's `field` key. Both were written by the same hand from the same
 * assumption, and eight of them named columns that are not on the businesses
 * table at all — `email` and `telephone` are on business_addresses, `employees`
 * is `total_employees`. The test passed on every one of them, because they
 * agreed.
 *
 * The browser no longer carries columns (see the note in returnTargets.ts:
 * nothing read them, and a request body must not choose a column), so there is
 * nothing left to compare. The real question — does this column exist — is
 * asked against the schema in ReturnTargetSchemaTest, which is what should
 * have been here from the start.
 */

it('reads a stored pointer as the list of codes it names', function () {
    // One code: every return written before 27 September 2026.
    expect(ReturnTargets::parse('form:tin'))->toBe(['form:tin']);

    // Several, which is what the officer can now tick.
    expect(ReturnTargets::parse('form:tin,form:trade_name'))
        ->toBe(['form:tin', 'form:trade_name']);

    // Whitespace and a trailing comma are formatting, not fields.
    expect(ReturnTargets::parse(' form:tin , form:email , '))
        ->toBe(['form:tin', 'form:email']);

    expect(ReturnTargets::parse(null))->toBe([])
        ->and(ReturnTargets::parse(''))->toBe([]);
});

it('separates the fields this API can write from the sections it cannot', function () {
    /*
     * `form:lines` is a repeating table and `form:documents` is a checklist of
     * uploads. Both are legitimate things to return a filing about, and neither
     * can be corrected by writing one column — so `scalars()` drops them and
     * the applicant is sent to the wizard step that owns them.
     */
    expect(ReturnTargets::scalars('form:tin,form:lines,form:documents,form:email'))
        ->toBe(['form:tin', 'form:email']);

    // A pointer naming nothing this API serves is empty, not an error.
    expect(ReturnTargets::scalars('form:lines'))->toBe([]);

    // And a code from another namespace entirely — a document type, a permit
    // code — is not a form field and never matches.
    expect(ReturnTargets::scalars('DTI_SEC_CDA,FSIC'))->toBe([]);
});
