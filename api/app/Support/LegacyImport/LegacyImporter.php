<?php

namespace App\Support\LegacyImport;

use App\Enums\PermitStatus;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\LegacyOwner;
use App\Models\Permit;
use App\Support\LegacyImport\Sources\RowSource;
use App\Support\Numbering;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Import the old register — Ken's checklist, 27 September 2026, "Migration 1"
 * and the IN half of "Migration 2".
 *
 * Two entry points over one pipeline:
 *
 *   preview($source)  the dry run. Reads every row, validates it, and says
 *                     what would be created, updated and rejected, and why.
 *                     Writes nothing.
 *   run($source)      the same reading and the same RowValidator, then the
 *                     writes, a chunk of rows per transaction.
 *
 * Because both go through RowValidator with the same Lookups, the counts the
 * super admin confirms are the counts the run produces — unless the register
 * changed in between, which the run's own counts then show.
 *
 * ── What a row writes ──────────────────────────────────────────────────────
 *
 * A business (matched on `legacy_id`), its address, the owner as the old
 * system names them (LegacyOwner), and — when the row has one — a permit
 * (matched on its own `legacy_id`). Re-importing the same export therefore
 * updates what the first run made rather than adding copies.
 *
 * The owner is linked to an account only when the row's email belongs to an
 * existing business owner account, or when the legacy owner has already been
 * claimed. Otherwise the business stays UNCLAIMED (`owner_user_id` null) until
 * its owner signs up and claims it (LegacyClaim). No account is created and no
 * password is ever set here.
 *
 * Once a business HAS an owner account, a re-import no longer rewrites its
 * name, address or owner: from that point the owner keeps those details in
 * BizTrack, and the old export is the stale copy. Its permits are still
 * upserted — a certificate the old system issued is the old system's record —
 * except that a permit BizTrack has since revoked, superseded or suspended
 * keeps that status (see `apply`).
 *
 * ── Chunks ─────────────────────────────────────────────────────────────────
 *
 * Rows are written CHUNK at a time, each chunk in one transaction. A failure
 * mid-file keeps the chunks already written, and because every row is keyed on
 * its legacy id, running the same file again finishes the job without
 * duplicating the first part. Large files go through a queued job
 * (RunLegacyImport) so the request does not time out; this class does not care
 * which.
 */
class LegacyImporter
{
    public const CHUNK = 200;

    /** How many rejected rows are kept with their reasons; the count is always exact. */
    public const KEEP_REJECTS = 500;

    /** @return array<string, mixed> */
    public function preview(RowSource $source): array
    {
        return $this->process($source, write: false);
    }

    /**
     * @param  (Closure(int $processed): void)|null  $onChunk  told after each chunk commits
     * @return array<string, mixed>
     */
    public function run(RowSource $source, ?Closure $onChunk = null): array
    {
        return $this->process($source, write: true, onChunk: $onChunk);
    }

    /** @return array<string, mixed> */
    private function process(RowSource $source, bool $write, ?Closure $onChunk = null): array
    {
        $lookups = Lookups::load();
        $validator = new RowValidator($lookups);

        $summary = [
            'total_rows' => 0,
            'will_create' => 0,
            'will_update' => 0,
            'rejected' => 0,
            'breakdown' => [
                'businesses_new' => 0,
                'businesses_updated' => 0,
                'permits_new' => 0,
                'permits_updated' => 0,
                'owners_linked' => 0,
                'owners_unclaimed' => 0,
                'reject_kinds' => array_fill_keys(RowValidator::KINDS, 0),
            ],
            'rejects' => [],
        ];
        $businessesSeen = [];
        $chunk = [];

        $flush = function () use (&$chunk, $write, $lookups, $onChunk, &$summary) {
            if ($write && $chunk !== []) {
                DB::transaction(function () use ($chunk, $lookups) {
                    foreach ($chunk as $accepted) {
                        $this->apply($accepted['data'], $lookups);
                    }
                });
            }
            $chunk = [];
            if ($onChunk !== null) {
                $onChunk($summary['total_rows']);
            }
        };

        foreach ($source->rows() as $row => $raw) {
            $summary['total_rows']++;
            $result = $validator->check($row, $raw);

            if (! $result['ok']) {
                $summary['rejected']++;
                foreach (array_unique(array_column($result['reasons'], 'kind')) as $kind) {
                    $summary['breakdown']['reject_kinds'][$kind]++;
                }
                if (count($summary['rejects']) < self::KEEP_REJECTS) {
                    unset($result['ok']);
                    $summary['rejects'][] = $result;
                }
            } else {
                $summary[$result['outcome'] === 'create' ? 'will_create' : 'will_update']++;

                $legacyId = $result['data']['legacy_id'];
                if (! isset($businessesSeen[$legacyId])) {
                    $businessesSeen[$legacyId] = true;
                    $summary['breakdown'][$result['business_new'] ? 'businesses_new' : 'businesses_updated']++;
                    if ($result['business_new']) {
                        $summary['breakdown'][$result['data']['account_user_id'] !== null ? 'owners_linked' : 'owners_unclaimed']++;
                    }
                }
                if ($result['permit_new'] !== null) {
                    $summary['breakdown'][$result['permit_new'] ? 'permits_new' : 'permits_updated']++;
                }

                $chunk[] = $result;
            }

            if (count($chunk) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        return $summary;
    }

    /**
     * Write one accepted row, and keep the lookups current so the next row
     * sees what this one made.
     *
     * @param  array<string, mixed>  $d
     */
    private function apply(array $d, Lookups $lookups): void
    {
        $known = $lookups->businesses[$d['legacy_id']] ?? null;
        $business = $known !== null
            ? Business::withTrashed()->findOrFail($known['id'])
            : new Business;

        if ($business->owner_user_id === null) {
            $owner = $this->owner($d, $business);

            $business->fill([
                'name' => $d['name'],
                'trade_name' => $d['trade_name'],
                'registration_number' => $d['registration_number'],
                'tin' => $d['tin'],
                'legacy_id' => $d['legacy_id'],
                'legacy_owner_id' => $owner->id,
            ]);
            if ($d['form'] !== null) {
                $business->registration_type = $d['form'];
                $business->form_of_organization = $d['form'];
            }
            if ($d['ban'] !== null) {
                $business->ban = $d['ban'];
            } elseif (! $business->exists) {
                $business->ban = Numbering::ban();
            }
            if (! $business->exists) {
                $business->status = 'active';
                $business->is_active = true;
            }
            // An owner already claimed elsewhere, or an email that is theirs.
            $business->owner_user_id = $owner->claimed_by_user_id;
            $business->save();

            $address = BusinessAddress::query()->where('business_id', $business->id)->first()
                ?? new BusinessAddress(['business_id' => $business->id]);
            $address->fill([
                'line1' => $d['address_line'],
                'street' => $d['address_line'],
                'barangay_id' => $d['barangay_id'],
            ])->save();

            $lookups->businesses[$d['legacy_id']] = ['id' => $business->id, 'claimed' => $business->owner_user_id !== null];
            $lookups->bans[Lookups::number($business->ban)] = $d['legacy_id'];
        }

        $p = $d['permit'];
        if ($p !== null) {
            $knownPermit = $lookups->permits[$p['legacy_id']] ?? null;
            $permit = $knownPermit !== null ? Permit::findOrFail($knownPermit['id']) : new Permit;

            $fields = [
                'legacy_id' => $p['legacy_id'],
                'business_id' => $business->id,
                'permit_type_id' => $p['permit_type_id'],
                'permit_number' => $p['permit_number'],
                'valid_from' => $p['valid_from'],
                'valid_until' => $p['valid_until'],
                'status' => $p['status'],
            ];

            /*
             * ── An end BizTrack wrote is not the export's to undo ─────────
             *
             * The status was written on every update, and an old export says
             * "active" for as long as the certificate's dates run. So the
             * super admin revoked an imported permit, re-imported the same
             * file — the documented way to update — and it was active again,
             * with /verify calling it valid; a permit a renewal had
             * superseded came back the same way (admin-audit-import row 41).
             *
             * Revoked and Superseded are both ends of a permit's life that
             * happened HERE, after the old system's record was taken, so the
             * export cannot know about them. A suspension is kept the same
             * way: an old file saying "active" never lifts one (Ken's call,
             * 5 October 2026). The rest of the row is still the old
             * register's and is still refreshed.
             */
            if ($permit->exists && in_array($permit->status, [PermitStatus::Revoked, PermitStatus::Superseded, PermitStatus::Suspended], true)) {
                unset($fields['status']);
            }

            $permit->fill($fields);
            if (! $permit->exists) {
                // Issued on paper: no BizTrack filing, no BizTrack officer.
                $permit->application_id = null;
                $permit->issued_at = $p['valid_from'];
            }
            $permit->save();

            $lookups->permits[$p['legacy_id']] = [
                'id' => $permit->id,
                'business_id' => $business->id,
                'business_legacy_id' => $d['legacy_id'],
            ];
            $lookups->permitNumbers[Lookups::number($p['permit_number'])] = $p['legacy_id'];

            $this->registeredNoLaterThan($business, $p['valid_from']);
        }
    }

    /**
     * Date the business by the old register, not by the import.
     *
     * `businesses.created_at` is what the dashboard's New and Closed
     * Businesses panel counts as a registration. Left to Eloquent it is the
     * moment the import ran, so loading the old register would draw every
     * business the City has ever licensed as "new" in one month — a spike
     * that is an artefact of the migration and reads as a boom.
     *
     * The old register's own date is the earliest permit it holds for the
     * business: the template has no separate registration date (MISD has not
     * sent a sample, docs/questions-for-malabon.md B28), and a business was
     * on the register at least from its first permit. Set like `issued_at`,
     * from the permit's first valid day, and only ever moved EARLIER — a
     * later row, or a re-import, cannot make a business younger than a
     * permit already on file says it is. A row without a permit leaves the
     * import date in place: there is nothing older to go on.
     *
     * Written with the query builder so the business's `updated_at` keeps
     * meaning "its details changed".
     */
    private function registeredNoLaterThan(Business $business, ?string $validFrom): void
    {
        if ($validFrom === null) {
            return;
        }

        DB::table('businesses')
            ->where('id', $business->id)
            ->where(fn ($q) => $q->whereNull('created_at')->orWhere('created_at', '>', $validFrom))
            ->update(['created_at' => $validFrom.' 00:00:00']);
    }

    /**
     * The legacy owner for this row: the same person the old system's owner id
     * names, else whoever the business already had, else a new record.
     *
     * @param  array<string, mixed>  $d
     */
    private function owner(array $d, Business $business): LegacyOwner
    {
        $o = $d['owner'];

        $owner = match (true) {
            $o['legacy_id'] !== null => LegacyOwner::firstOrNew(['legacy_id' => $o['legacy_id']]),
            $business->legacy_owner_id !== null => LegacyOwner::find($business->legacy_owner_id) ?? new LegacyOwner,
            default => new LegacyOwner,
        };

        $owner->fill([
            'first_name' => $o['first_name'],
            'middle_name' => $o['middle_name'],
            'last_name' => $o['last_name'],
            'suffix' => $o['suffix'],
            'email' => $o['email'],
            'mobile_number' => $o['mobile_number'],
        ]);

        if ($owner->claimed_by_user_id === null && $d['account_user_id'] !== null) {
            $owner->claimed_by_user_id = $d['account_user_id'];
            $owner->claimed_at = now();
        }
        $owner->save();

        return $owner;
    }
}
