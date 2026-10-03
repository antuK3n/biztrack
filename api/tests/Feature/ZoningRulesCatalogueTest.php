<?php

use App\Support\Zoning\Rulebook;

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

it('points every implemented rule at a line of code that exists', function () {
    foreach (zrRules() as $rule) {
        if ($rule['status'] !== 'implemented') {
            continue;
        }
        [$file, $line] = explode(':', $rule['implemented']['check'], 2);
        $path = zrRoot().'/'.$file;
        expect(is_file($path))->toBeTrue("{$rule['id']} checks a file that does not exist: {$file}");
        expect((int) $line)->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(count(file($path)), "{$rule['id']} points past the end of {$file}");
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
