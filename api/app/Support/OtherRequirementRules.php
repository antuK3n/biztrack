<?php

namespace App\Support;

/**
 * Which Other Requirements a business must hold because of what it does.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * The Revenue Code names permits a business must secure on top of the Mayor's
 * Permit — a Liquor Permit before serving a drink (Art. T), a tobacco permit
 * before selling a pack (Art. U), a Health Certificate for each food handler
 * (Art. 4D). Until 5 October 2026 the system knew these only as FEES:
 * tick "sells liquor" and `FeeCalculator` priced the liquor filing fee, and
 * nothing told the applicant there was a permit behind the fee or asked for
 * what the office needs to issue it. Client: *"I am planning to have rules
 * that will tell which Other Requirements are required to have for a business.
 * Does the revenue code … state something about this?"* It does, and these
 * rows are that statement.
 *
 * ── One table, two readers ───────────────────────────────────────────────────
 *
 * The wizard reads it BEFORE submission (`PaymentController::feePreview` hands
 * back `other_requirements` beside the estimate) so the applicant sees what
 * the filing commits them to; `WorkflowService::submit` reads it AFTER, and
 * raises each row that asks for something as a system `OfficerRequest` under
 * Other Requirements — the DENR precedent, keyed by `system_key` so a second
 * submit cannot raise it twice and an officer typing the same title by hand
 * is not mistaken for it.
 *
 * ── Conditions are the fee engine's ──────────────────────────────────────────
 *
 * `conditions` has the shape `fee_rules.conditions` has — `flags` and
 * `business_category`, every key ANDed, any value within a key enough — and is
 * matched against the same normalised profile (`FeeCalculator::facts`). A rule
 * that fires a fee and a rule that raises a requirement therefore read one
 * answer, which is the only way "you were charged for X" and "you must hold X"
 * stay in step.
 *
 * ── What each row may honestly ask ───────────────────────────────────────────
 *
 * Most of these permits are issued by the City itself, through BPLO, on this
 * very filing. Asking the applicant to UPLOAD a Liquor Permit the City has not
 * yet issued would be a dead end, so a row raises a requirement only where the
 * Code gives the office something it needs from the applicant: the distance
 * to the nearest school or church (Sec. 3T.04), the staff's Health
 * Certificates (Art. 4D). Rows with `request_type => null` are told before
 * submission and raise nothing after — a tobacco permit needs nothing more
 * than the fee already assessed.
 *
 * ── The quantity-priced permits were dropped, 5 October 2026 ────────────────
 *
 * The storage permit for flammables (Art. O), the engine and machinery
 * inspection (Art. N) and the lumberyard Special Permit (Art. AC) were rows
 * here for a day. The first two are priced by a quantity — litres,
 * horsepower — the applicant would have had to reply with and BPLO re-assess
 * by hand. Client: *"safe to not include this for less complexity."* The
 * three wizard flags that turned them on went with them. Bringing one back
 * means a row here AND its flag back in `FeeProfileStep`'s BUSINESS_FLAGS.
 */
final class OtherRequirementRules
{
    /** Prefix every `system_key` carries, so the family is findable as one. */
    public const KEY_PREFIX = 'rule.';

    /**
     * @var list<array{
     *   key: string,
     *   title: string,
     *   article: string,
     *   department: string,
     *   conditions: array<string, list<string>>,
     *   summary: string,
     *   request_type: 'document'|'message'|null,
     *   description: string|null,
     * }>
     */
    private const ROWS = [
        [
            'key' => 'liquor_permit',
            'title' => 'Liquor Permit',
            'article' => 'Revenue Code Art. T, Sec. 3T.01',
            'department' => 'BPLO',
            'conditions' => ['flags' => ['sells_liquor']],
            /*
             * Told, not asked, since 5 October 2026. It asked the applicant for the
             * nearest school, church or hospital and its distance (Sec. 3T.04);
             * client: *"No need to ask for that in the other requirement. Remove
             * it."* BPLO checks the distance itself when it issues the permit.
             */
            'summary' => 'A Liquor Permit from BPLO before you sell or serve liquor. Its fee is on your Tax Order of Payment.',
            'request_type' => null,
            'description' => null,
        ],
        [
            'key' => 'tobacco_permit',
            'title' => "Mayor's Permit to sell tobacco",
            'article' => 'Revenue Code Art. U, Sec. 3U.01',
            'department' => 'BPLO',
            'conditions' => ['flags' => ['sells_tobacco_retail', 'sells_tobacco_wholesale']],
            'summary' => "A Mayor's Permit to sell tobacco or cigarettes, issued by BPLO with this filing. The annual fee is on your assessment.",
            'request_type' => null,
            'description' => null,
        ],
        [
            'key' => 'health_certificates',
            'title' => 'Health Certificates for your staff',
            'article' => 'Revenue Code Art. 4D (Sanitary Inspection and Health Certificate Fees)',
            'department' => 'CHO',
            'conditions' => ['flags' => ['employees_need_health_certificates']],
            'summary' => 'A Health Certificate from the City Health Office for every employee who handles food or gives personal-care services. Upload them under Other Requirements once you have them.',
            'request_type' => 'document',
            'description' => 'You said your staff handle food or give personal-care services, so each of them needs a Health Certificate from the City Health Office (Revenue Code Art. 4D). '
                .'Upload each certificate here as you receive it. The Sanitary Permit is released once the office has seen them.',
        ],
        [
            'key' => 'ambulant_vendor',
            'title' => 'Peddler or ambulant vendor',
            'article' => 'Revenue Code Sec. 3X.03 and 3X.05',
            'department' => 'BPLO',
            'conditions' => ['flags' => ['is_ambulant_vendor']],
            'summary' => "No zoning clearance is needed (Sec. 3X.05). Where and when you may sell is set by the City Mayor's Executive Order on peddlers (Sec. 3X.03).",
            'request_type' => null,
            'description' => null,
        ],
    ];

    /**
     * Every rule that holds for this profile, in table order.
     *
     * @param  array{flags?: list<string>, categories?: list<string>}  $profile  the normalised profile (`FeeCalculator::facts`)
     * @return list<array<string, mixed>>
     */
    public static function matching(array $profile): array
    {
        return array_values(array_filter(
            self::ROWS,
            fn (array $row) => self::holds($row['conditions'], $profile),
        ));
    }

    /**
     * The rows the wizard shows before submission: what the filing will also
     * need, and whether anything is asked of the applicant afterwards.
     *
     * @return list<array{key: string, title: string, article: string, summary: string, asks: bool}>
     */
    public static function preview(array $profile): array
    {
        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'title' => $row['title'],
            'article' => $row['article'],
            'summary' => $row['summary'],
            'asks' => $row['request_type'] !== null,
        ], self::matching($profile));
    }

    /**
     * The rows that raise a requirement after submission — those with
     * something to ask. A row that only informs is not in this list.
     *
     * @return list<array<string, mixed>>
     */
    public static function raisable(array $profile): array
    {
        return array_values(array_filter(
            self::matching($profile),
            fn (array $row) => $row['request_type'] !== null,
        ));
    }

    /** The `system_key` a rule's requirement is stored under. */
    public static function systemKey(string $ruleKey): string
    {
        return self::KEY_PREFIX.$ruleKey;
    }

    /**
     * Every flag any rule reads, so a test can hold the wizard to asking them.
     *
     * @return list<string>
     */
    public static function flagsRead(): array
    {
        $flags = [];
        foreach (self::ROWS as $row) {
            foreach ($row['conditions']['flags'] ?? [] as $flag) {
                $flags[$flag] = true;
            }
        }

        return array_keys($flags);
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return self::ROWS;
    }

    /**
     * `fee_rules.conditions` semantics: every key must hold; within a key, any
     * listed value is enough. Kept to the two keys a requirement can turn on —
     * the quantities and tiers a FEE condition may read say how much, not
     * whether.
     *
     * @param  array<string, list<string>>  $conditions
     */
    private static function holds(array $conditions, array $profile): bool
    {
        foreach ($conditions as $key => $want) {
            $have = match ($key) {
                'flags' => $profile['flags'] ?? [],
                'business_category' => $profile['categories'] ?? [],
                default => [],
            };
            if (array_intersect((array) $want, (array) $have) === []) {
                return false;
            }
        }

        return true;
    }
}
