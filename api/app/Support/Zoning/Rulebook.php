<?php

namespace App\Support\Zoning;

use Illuminate\Support\Facades\Cache;

/**
 * `docs/zoning-ordinance/rules.json`, read by id.
 *
 * Every finding the check produces takes its title, its plain-English rule and
 * its citation from here rather than from a string in PHP, so the words an
 * applicant reads and the inventory CPDO can audit are the same words. A rule
 * id the check uses that is missing from the file is a bug, and
 * ZoningRulesCatalogueTest fails on it.
 *
 * Cached for an hour like the use lists in ZoningConformance: a committed
 * document that changes when the ordinance does.
 */
final class Rulebook
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return Cache::remember('zoning.ordinance.rules', 3600, function (): array {
            $path = self::path();
            $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $out = [];
            foreach ((array) ($raw['rules'] ?? []) as $rule) {
                if (isset($rule['id'])) {
                    $out[(string) $rule['id']] = $rule;
                }
            }

            return $out;
        });
    }

    public static function path(): string
    {
        return base_path('../docs/zoning-ordinance/rules.json');
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** "Art. V §2.1 · p. 27" — the citation an officer can find on paper. */
    public static function citation(string $id): string
    {
        $rule = self::get($id);
        if ($rule === null) {
            return $id;
        }

        return (string) ($rule['citation'] ?? $id);
    }
}
