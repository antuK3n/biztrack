<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\UnbilledPermitFee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Two states the register cannot currently produce, for the E2E stack.
 *
 * ── Why a command and not a factory in the spec ────────────────────────────
 *
 * The end-to-end suite runs against a COPY of the live register
 * (scripts/e2e-stack.sh), and that register holds neither of the states the
 * renewal screens were built for: measured 1 October 2026, it has no clearance
 * lapsed at all, let alone one past the 36-month cutoff, and no unbilled fee
 * carrying a surcharge. A spec written against it would skip itself for ever
 * and read as passing — which is worse than no spec, because the suite would
 * report green over two screens nobody had checked.
 *
 * Neither state can be reached through the API either. An unbilled fee is
 * written by the workflow when a late clearance renewal is issued, which needs
 * an inspection conducted months after an expiry; backdating that through the
 * UI is not possible and should not be.
 *
 * ── Refuses to run against the real register ───────────────────────────────
 *
 * It writes rows and supersedes nothing, but it writes them into whatever
 * database it is pointed at, and the live one holds real tester filings
 * (AGENTS.md §2.2). The guard is on the FILE NAME rather than on an
 * environment flag because the E2E stack runs with the ordinary `.env` and
 * only overrides `DB_DATABASE` — the file is the only thing that differs, so
 * it is the only honest thing to check.
 */
class SeedRenewalUiFixtures extends Command
{
    protected $signature = 'biztrack:seed-renewal-ui-fixtures';

    protected $description = 'Seed a long-lapsed clearance and a late unbilled fee into the E2E database.';

    public function handle(): int
    {
        $database = (string) config('database.connections.sqlite.database');

        if (! str_contains(str_replace('\\', '/', $database), '/e2e.sqlite')) {
            $this->error('Refusing to run: this is not the e2e database ('.$database.').');

            return self::FAILURE;
        }

        $owner = User::where('email', 'owner@biztrack.local')->first();
        if ($owner === null) {
            $this->error('No owner@biztrack.local in this database.');

            return self::FAILURE;
        }

        $business = Business::where('owner_user_id', $owner->id)
            ->whereHas('permits')
            ->orderBy('id')
            ->first();

        if ($business === null) {
            $this->error('That owner holds no business with permits.');

            return self::FAILURE;
        }

        $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

        /*
         * 1. A clearance lapsed four years ago — past the 36-month cutoff, so
         *    the renewal picker must offer it greyed out rather than let the
         *    applicant fill in a wizard the server will refuse.
         */
        $expired = CarbonImmutable::now()->subYears(4);

        $lapsed = Permit::firstOrCreate(
            ['permit_number' => 'E2E-LAPSED-SANITARY'],
            [
                'business_id' => $business->id,
                'application_id' => $business->permits()->value('application_id'),
                'permit_type_id' => $sanitary->id,
                'status' => 'expired',
                'valid_from' => $expired->subYear()->toDateString(),
                'valid_until' => $expired->toDateString(),
                'issued_at' => $expired->subYear(),
            ],
        );

        /*
         * 2. A fee already issued unbilled, carrying a late surcharge, so the
         *    owner's Profile shows the "Fees due with your January renewal"
         *    card with a penalty line rather than a bare fee.
         */
        $fee = UnbilledPermitFee::firstOrCreate(
            ['application_id' => $lapsed->application_id, 'permit_type_id' => $sanitary->id],
            [
                'business_id' => $business->id,
                'amount' => 1100.00,
                'surcharge' => 275.00,
                'interest' => 82.50,
                'months_late' => 3,
                'incurred_at' => CarbonImmutable::now()->subMonths(4),
            ],
        );

        $this->info("Business #{$business->id} ({$business->name})");
        $this->info("  lapsed permit  #{$lapsed->id} — expired {$lapsed->valid_until}");
        $this->info("  unbilled fee   #{$fee->id} — ₱{$fee->amount} + ₱{$fee->surcharge} surcharge");

        return self::SUCCESS;
    }
}
