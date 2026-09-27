<?php

namespace App\Support\LegacyImport;

use App\Enums\PermitStatus;
use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * Check one legacy row and say what importing it would do.
 *
 * Shared by the CSV and ODBC imports and by the dry run and the real run, so
 * the preview the super admin confirms is produced by exactly the code that
 * then writes. It is stateful across one import: it remembers the ACCEPTED
 * rows it has seen, which is how a duplicate inside the file is caught and how
 * row 40 knows the business row 3 is about to create. A rejected row leaves no
 * trace in that memory, so a later, correct row for the same business is not
 * refused as its duplicate.
 *
 * Every reason carries a `kind` from a short fixed list — the five the brief
 * names (bad date, unknown barangay, unknown permit type, missing owner,
 * duplicate) plus `missing` and `invalid` for everything else — so the screen
 * can count them, and a plain-language `message` that names the column.
 */
class RowValidator
{
    public const KINDS = ['bad_date', 'unknown_barangay', 'unknown_permit_type', 'missing_owner', 'duplicate', 'missing', 'invalid'];

    /** @var array<string, array{row: int, signature: string, permitless: bool}> */
    private array $seenBusinesses = [];

    /** @var array<string, int> legacy permit id => row */
    private array $seenPermits = [];

    /** @var array<string, string> permit number => legacy permit id */
    private array $seenPermitNumbers = [];

    /** @var array<string, string> account no. => legacy business id */
    private array $seenBans = [];

    public function __construct(private Lookups $lookups, private ?CarbonImmutable $today = null)
    {
        $this->today ??= CarbonImmutable::today();
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array{ok: bool, row: int, reasons: list<array{kind: string, message: string}>, data?: array<string, mixed>, business_new?: bool, permit_new?: bool|null, outcome?: string}
     */
    public function check(int $row, array $raw): array
    {
        $reasons = [];
        $fail = function (string $kind, string $message) use (&$reasons) {
            $reasons[] = ['kind' => $kind, 'message' => $message];
        };

        foreach ($raw as $column => $value) {
            if ($value !== null && mb_strlen($value) > 255) {
                $fail('invalid', "{$column} is longer than 255 characters.");
            }
        }

        $legacyBusinessId = $raw['legacy_business_id'];
        if ($legacyBusinessId === null) {
            $fail('missing', 'legacy_business_id is empty. Every row needs the old system’s ID for the business.');
        }
        if ($raw['business_name'] === null) {
            $fail('missing', 'business_name is empty.');
        }
        if ($raw['address_line'] === null) {
            $fail('missing', 'address_line is empty.');
        }

        if ($legacyBusinessId === Template::EXAMPLE['legacy_business_id']
            && $raw['business_name'] === Template::EXAMPLE['business_name']) {
            $fail('invalid', 'This is the template’s example row. Delete it before importing.');
        }

        // ── Owner ────────────────────────────────────────────────────────
        if ($raw['owner_first_name'] === null || $raw['owner_last_name'] === null) {
            $fail('missing_owner', 'The owner’s '.($raw['owner_last_name'] === null ? 'surname' : 'given name').' is empty. BizTrack needs both to let the owner claim the business.');
        }
        $email = $raw['owner_email'] !== null ? strtolower($raw['owner_email']) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fail('invalid', "owner_email “{$raw['owner_email']}” is not an email address.");
        }

        // ── Business ─────────────────────────────────────────────────────
        $barangayId = null;
        if ($raw['barangay'] === null) {
            $fail('unknown_barangay', 'barangay is empty.');
        } else {
            $barangayId = $this->lookups->barangays[Lookups::key($raw['barangay'])] ?? null;
            if ($barangayId === null) {
                $fail('unknown_barangay', "“{$raw['barangay']}” is not one of Malabon’s barangays as BizTrack lists them.");
            }
        }

        $form = null;
        if ($raw['organization_type'] !== null) {
            $form = Business::normalizeRegistrationType(strtolower($raw['organization_type']))
                ?? Business::normalizeRegistrationType(strtoupper($raw['organization_type']));
            if ($form === null) {
                $fail('invalid', "organization_type “{$raw['organization_type']}” is not sole_proprietorship, partnership, corporation or cooperative.");
            }
        }

        $ban = $raw['business_account_no'];
        if ($ban !== null && $legacyBusinessId !== null) {
            $key = Lookups::number($ban);
            $holder = $this->lookups->bans[$key] ?? $this->seenBans[$key] ?? null;
            if ($holder !== null && $holder !== $legacyBusinessId) {
                $fail('duplicate', "Business account no. {$ban} already belongs to ".($holder === '' ? 'a business registered in BizTrack' : "legacy business {$holder}").'.');
            }
        }

        // ── Permit ───────────────────────────────────────────────────────
        $permitColumns = Template::permitColumns();
        $hasPermit = collect($permitColumns)->contains(fn ($c) => $raw[$c] !== null) || $raw['permit_status'] !== null;
        $permit = null;

        if ($hasPermit) {
            foreach ($permitColumns as $column) {
                if ($raw[$column] === null) {
                    $fail('missing', "{$column} is empty. A row with a permit needs all of: ".implode(', ', $permitColumns).'.');
                }
            }

            $permitTypeId = null;
            if ($raw['permit_type'] !== null) {
                $permitTypeId = $this->lookups->permitTypes[Lookups::key($raw['permit_type'])] ?? null;
                if ($permitTypeId === null) {
                    $fail('unknown_permit_type', "permit_type “{$raw['permit_type']}” is not a permit BizTrack issues.");
                }
            }

            $from = $this->date($raw['valid_from']);
            $until = $this->date($raw['valid_until']);
            if ($raw['valid_from'] !== null && $from === null) {
                $fail('bad_date', "valid_from “{$raw['valid_from']}” is not a date. Use YYYY-MM-DD or MM/DD/YYYY.");
            }
            if ($raw['valid_until'] !== null && $until === null) {
                $fail('bad_date', "valid_until “{$raw['valid_until']}” is not a date. Use YYYY-MM-DD or MM/DD/YYYY.");
            }
            if ($from !== null && $until !== null && $until->lt($from)) {
                $fail('bad_date', "valid_until ({$until->toDateString()}) is before valid_from ({$from->toDateString()}).");
            }

            $status = null;
            if ($raw['permit_status'] !== null) {
                $status = PermitStatus::tryFrom(strtolower($raw['permit_status']));
                if (! in_array($status, [PermitStatus::Active, PermitStatus::Expired, PermitStatus::Suspended, PermitStatus::Revoked], true)) {
                    $fail('invalid', "permit_status “{$raw['permit_status']}” is not active, expired, suspended or revoked.");
                    $status = null;
                }
            }

            $legacyPermitId = $raw['legacy_permit_id'];
            if ($legacyPermitId !== null) {
                if (isset($this->seenPermits[$legacyPermitId])) {
                    $fail('duplicate', "legacy_permit_id {$legacyPermitId} already appears on row {$this->seenPermits[$legacyPermitId]}.");
                }
                $existing = $this->lookups->permits[$legacyPermitId] ?? null;
                if ($existing !== null && $existing['business_legacy_id'] !== $legacyBusinessId) {
                    $fail('duplicate', "legacy_permit_id {$legacyPermitId} was imported before under a different business.");
                }
            }

            if ($raw['permit_number'] !== null && $legacyPermitId !== null) {
                $key = Lookups::number($raw['permit_number']);
                $holder = $this->lookups->permitNumbers[$key] ?? $this->seenPermitNumbers[$key] ?? null;
                if ($holder !== null && $holder !== $legacyPermitId) {
                    $fail('duplicate', "Permit number {$raw['permit_number']} already belongs to ".($holder === '' ? 'a permit BizTrack issued' : "legacy permit {$holder}").'.');
                }
            }

            $permit = [
                'legacy_id' => $legacyPermitId,
                'permit_type_id' => $permitTypeId,
                'permit_number' => $raw['permit_number'],
                'valid_from' => $from?->toDateString(),
                'valid_until' => $until?->toDateString(),
                'status' => ($status ?? ($until !== null && $until->lt($this->today) ? PermitStatus::Expired : PermitStatus::Active))->value,
            ];
        }

        // ── The same business twice in one file ──────────────────────────
        $signature = implode('|', array_map(fn ($c) => Lookups::key($raw[$c]), ['business_name', 'address_line', 'barangay', 'owner_last_name']));
        if ($legacyBusinessId !== null && isset($this->seenBusinesses[$legacyBusinessId])) {
            $seen = $this->seenBusinesses[$legacyBusinessId];
            if ($seen['signature'] !== $signature) {
                $fail('duplicate', "legacy_business_id {$legacyBusinessId} is on row {$seen['row']} with a different name, address or owner. Every row for one business must repeat the same details.");
            } elseif (! $hasPermit) {
                $fail('duplicate', "legacy_business_id {$legacyBusinessId} already appears on row {$seen['row']}, and this row adds no permit.");
            }
        }

        if ($reasons !== []) {
            return [
                'ok' => false,
                'row' => $row,
                'legacy_business_id' => $legacyBusinessId,
                'business_name' => $raw['business_name'],
                'reasons' => $reasons,
            ];
        }

        // ── Accepted: remember it, and say what it will do ────────────────
        $businessNew = ! isset($this->lookups->businesses[$legacyBusinessId]) && ! isset($this->seenBusinesses[$legacyBusinessId]);
        $this->seenBusinesses[$legacyBusinessId] ??= ['row' => $row, 'signature' => $signature, 'permitless' => ! $hasPermit];
        if ($ban !== null) {
            $this->seenBans[Lookups::number($ban)] = $legacyBusinessId;
        }

        $permitNew = null;
        if ($permit !== null) {
            $permitNew = ! isset($this->lookups->permits[$permit['legacy_id']]);
            $this->seenPermits[$permit['legacy_id']] = $row;
            $this->seenPermitNumbers[Lookups::number($permit['permit_number'])] = $permit['legacy_id'];
        }

        return [
            'ok' => true,
            'row' => $row,
            'reasons' => [],
            'business_new' => $businessNew,
            'permit_new' => $permitNew,
            'outcome' => $businessNew || $permitNew === true ? 'create' : 'update',
            'data' => [
                'legacy_id' => $legacyBusinessId,
                'name' => $raw['business_name'],
                'trade_name' => $raw['trade_name'],
                'form' => $form,
                'registration_number' => $raw['registration_number'],
                'ban' => $ban,
                'tin' => $raw['tin'],
                'address_line' => $raw['address_line'],
                'barangay_id' => $barangayId,
                'owner' => [
                    'legacy_id' => $raw['owner_legacy_id'],
                    'first_name' => $raw['owner_first_name'],
                    'middle_name' => $raw['owner_middle_name'],
                    'last_name' => $raw['owner_last_name'],
                    'suffix' => $raw['owner_suffix'],
                    'email' => $email,
                    'mobile_number' => $raw['owner_mobile'],
                ],
                'account_user_id' => $email !== null ? ($this->lookups->ownerAccounts[$email] ?? null) : null,
                'permit' => $permit,
            ],
        ];
    }

    /**
     * YYYY-MM-DD (a time after it is ignored — ODBC hands dates back as
     * timestamps) or MM/DD/YYYY, month first, the order a Philippine
     * spreadsheet uses. A slashed date is ALWAYS read month first: a DD/MM
     * export would have its day-over-12 rows rejected and the rest silently
     * misread, which is why the guide says "month first" and why YYYY-MM-DD is
     * the format to ask MISD for (docs/questions-for-malabon.md, legacy import).
     */
    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
            [$mo, $d, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if (! checkdate($mo, $d, $y) || $y < 1900 || $y > 2100) {
            return null;
        }

        return CarbonImmutable::create($y, $mo, $d)->startOfDay();
    }
}
