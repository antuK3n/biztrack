<?php

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\Zoning\Rulebook;
use App\Support\Zoning\ZoningCheck;
use App\Support\Zoning\ZoningContext;

/*
 * docs/zoning-ordinance/rules.json is the inventory of every normative
 * statement in City Ordinance No. 24-2018, and each entry claims a status. A
 * claim nothing checks is the kind that drifts: a test renamed, a check moved,
 * a question renumbered, and the inventory goes on saying "implemented" about
 * code that no longer exists. These hold every claim to the repository.
 */

function zrRules(): array
{
    $doc = json_decode((string) file_get_contents(Rulebook::path()), true);

    return $doc['rules'] ?? [];
}

function zrRoot(): string
{
    return dirname(base_path());
}

it('gives every rule in the inventory one of the four statuses, with what that status needs', function () {
    $rules = zrRules();
    expect(count($rules))->toBeGreaterThan(300);

    foreach ($rules as $rule) {
        expect($rule['status'])->toBeIn(['implemented', 'shown', 'not_applicable', 'question'], $rule['id']);
        expect($rule['verbatim'] ?? '')->not->toBe('', "{$rule['id']} has no verbatim text");
        expect($rule['citation'] ?? '')->toMatch('/^(Art\. [IVX]+|Annex [A-C]|Preamble)/', $rule['id']);
        // A not-applicable reason may not claim the rule is shown somewhere:
        // that is a "shown" entry, and must say where (audit: VII-3, -6, -9).
        if ($rule['status'] === 'not_applicable') {
            expect(preg_match('/shows? it|shown (?:to|on|in)/i', $rule['not_applicable']['reason']))->toBe(0, "{$rule['id']}: a not-applicable reason that says it is shown");
        }
        match ($rule['status']) {
            'implemented' => expect($rule['implemented']['check'] ?? '')->not->toBe('', $rule['id'])
                ->and($rule['implemented']['test'] ?? '')->not->toBe('', $rule['id']),
            'shown' => expect(trim($rule['shown']['where'] ?? ''))->not->toBe('', $rule['id']),
            'not_applicable' => expect(mb_strlen(trim($rule['not_applicable']['reason'] ?? '')))->toBeGreaterThan(30, "{$rule['id']} needs a specific reason"),
            'question' => expect($rule['question']['id'] ?? '')->not->toBe('', $rule['id']),
        };
    }

    $ids = array_column($rules, 'id');
    expect($ids)->toBe(array_values(array_unique($ids)));
});

it('names, for every implemented rule, a test that exists', function () {
    foreach (zrRules() as $rule) {
        if ($rule['status'] !== 'implemented') {
            continue;
        }
        [$file, $name] = explode('::', $rule['implemented']['test'], 2);
        $path = zrRoot().'/'.$file;
        expect(is_file($path))->toBeTrue("{$rule['id']} names a test file that does not exist: {$file}");
        $source = (string) file_get_contents($path);
        $quoted = "it('".str_replace("'", "\\'", $name)."'";
        expect(str_contains($source, $quoted))->toBeTrue("{$rule['id']} names a test that does not exist: {$name}");
    }
});

it('points every implemented rule at the line of code that checks it, never at a comment', function () {
    foreach (zrRules() as $rule) {
        if ($rule['status'] !== 'implemented') {
            continue;
        }
        [$file, $line] = explode(':', $rule['implemented']['check'], 2);
        $path = zrRoot().'/'.$file;
        expect(is_file($path))->toBeTrue("{$rule['id']} checks a file that does not exist: {$file}");
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        expect((int) $line)->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(count($lines), "{$rule['id']} points past the end of {$file}");

        /*
         * The line must BE the check: it quotes the rule's id, or the marker
         * rules.json names for a check not keyed by id (an inheritance table,
         * a payment gate). And it must be code. The audit of 3 October 2026
         * found A-63 pointing at a docblock and III-1 at an unrelated rule's
         * line; a claim like that now fails here.
         */
        $text = $lines[(int) $line - 1];
        $marker = $rule['implemented']['marker'] ?? "'{$rule['id']}'";
        expect(str_contains($text, $marker))->toBeTrue("{$rule['id']}: {$file}:{$line} does not hold {$marker}");
        expect(preg_match('/^\s*(?:\*|\/\/|\/\*|#)/', $text))->toBe(0, "{$rule['id']}: {$file}:{$line} is a comment, not a check");
    }
});

it('names, for every rule a zoning test proves, the rule inside that test', function () {
    /*
     * A test claimed as the proof of a rule must assert something about that
     * rule — find its finding, read its status — so its id appears in the
     * test's body. Held to the zoning suites, whose tests are about findings.
     * The workflow gates proven elsewhere (AmendmentFlowTest, ClearanceStage-
     * Test, ZoningOfficerTest) assert the gate, not a finding, and are held to
     * their names above.
     */
    foreach (zrRules() as $rule) {
        if ($rule['status'] !== 'implemented') {
            continue;
        }
        [$file, $name] = explode('::', $rule['implemented']['test'], 2);
        if (! in_array(basename($file), ['ZoningOrdinanceRulesTest.php', 'ZoningTradeMatchingTest.php'], true)) {
            continue;
        }
        $source = (string) file_get_contents(zrRoot().'/'.$file);
        $start = strpos($source, "it('".str_replace("'", "\\'", $name)."'");
        expect($start)->not->toBeFalse("{$rule['id']}: no test named {$name}");
        $end = strpos($source, "\n});", (int) $start);
        $body = substr($source, (int) $start, $end === false ? null : $end - (int) $start);
        expect(str_contains($body, "'{$rule['id']}'"))->toBeTrue("{$rule['id']}: “{$name}” never names the rule it is said to prove");
    }
});

it('links every question to an entry in the register of questions for Malabon', function () {
    $register = (string) file_get_contents(zrRoot().'/docs/questions-for-malabon.md');
    foreach (zrRules() as $rule) {
        foreach (['question', 'also_question'] as $key) {
            $id = $rule[$key]['id'] ?? null;
            if ($id === null) {
                continue;
            }
            expect(preg_match('/^## '.preg_quote($id, '/').'\./m', $register))->toBe(1, "{$rule['id']} cites {$id}, which is not in docs/questions-for-malabon.md");
        }
    }
});

it('finds every rule the zoning check cites in the inventory', function () {
    $known = array_flip(array_column(zrRules(), 'id'));
    $source = '';
    foreach (glob(base_path('app/Support/Zoning/*.php')) as $file) {
        $source .= file_get_contents($file);
    }
    preg_match_all("/'((?:I|II|III|IV|V|VI|VII|VIII|IX|A|ANNEX)-[A-Z0-9.\\-]+)'/", $source, $m);

    // I-1 and I-2 are zone codes, not rule ids.
    $cited = array_diff(array_unique($m[1]), ['I-1', 'I-2']);
    $unknown = array_values(array_filter($cited, fn ($id) => ! isset($known[$id])));
    expect($unknown)->toBe([]);
});

it('keeps the status counts in the inventory’s header honest', function () {
    $doc = json_decode((string) file_get_contents(Rulebook::path()), true);
    $counts = array_count_values(array_column($doc['rules'], 'status'));
    ksort($counts);

    expect($doc['status_counts'])->toBe($counts);
});

it('lists in each rule’s data every question its findings ask', function () {
    /*
     * data_needed says what a rule reads. It drifted: VI-4-4 began asking
     * whether a large parking lot is planted and permeable, and its entry did
     * not say so. Run a spread of filings and hold every question a finding
     * asks to the data of one of the rules it cites.
     */
    $rules = collect(zrRules())->keyBy('id');
    $runs = [
        ['Niugan', '68100', 'Pay parking lot', ['vehicle_use' => 'parking_lot', 'parking_slots' => 25]],
        ['Muzon', '49221', 'Garage', ['vehicle_use' => 'business_parking']],
        ['Dampalit', '49230', 'Trucking garage', []],
        ['Longos', '47111', '', ['home_based' => true, 'new_construction' => true, 'has_sign' => true, 'beside_waterway' => true]],
        ['Concepcion', '47111', '', ['heritage_house' => false, 'new_construction' => true]],
        ['Catmon', '10711', '', ['home_based' => true]],
        ['Acacia', '96301', '', []],
        ['Niugan', '55101', '', []],
        ['Potrero', '68100', 'Lessor', []],
        ['Catmon', '47300', '', []],
    ];
    foreach ($runs as [$barangay, $code, $description, $facts]) {
        $ctx = ZoningContext::fromRequest([
            'barangay_id' => Barangay::where('name', $barangay)->value('id'),
            'application_type' => 'new',
            'lines' => [['psic_code_id' => PsicCode::where('code', $code)->value('id'), 'description' => $description]],
            'zoning_facts' => $facts,
        ]);
        foreach (ZoningCheck::evaluate($ctx)['findings'] as $f) {
            $data = collect($f['rules'])->flatMap(fn ($id) => $rules[$id]['data_needed'] ?? [])->all();
            foreach (array_merge($f['asks'], $f['officer_asks']) as $fact) {
                expect(in_array($fact, $data, true))->toBeTrue("{$f['rule']} asks {$fact}, which none of ".implode(', ', $f['rules']).' lists in data_needed');
            }
        }
    }
    expect($rules['VI-4-4']['data_needed'])->toContain('parking_landscaped');
});
