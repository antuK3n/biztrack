<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Five businesses whose permits sit at the five renewal states.
 *
 * ── Why these five and not five arbitrary shops ────────────────────────────
 *
 * Asked for by the client on 3 October 2026, to check the renewal rules by
 * hand: *"provide me 5 complete dummy datas that have permits nearing
 * expiration so that I can personally test them."*
 *
 * Every rule the renewal picker enforces has exactly one business here, so a
 * screen that is wrong is wrong for a named reason. Nothing is "nearly
 * expired" in general — in each case ONE permit is placed on one side of one
 * boundary and the rest are left plainly in force:
 *
 *   1  a clearance inside the 30-day window    renewable today
 *   2  nothing due at all                      the whole "Still valid" group
 *   3  a clearance lapsed, inside the cap      renewable, surcharged
 *   4  a clearance lapsed past the 36-month cap  refused, New Application
 *   5  Mayor's Permit term ending 20 January   locked until 1 January
 *
 * ── Every business holds the FULL SET, and that is the correction ──────────
 *
 * The first version of this command gave each shop the single permit its
 * scenario needed — a Sanitary Permit and nothing else. The client caught it
 * the same day: *"How are you able to create them without the business permit
 * there? It is impossible for them to have other permits when they don't have
 * business permit. Did you just bypass any validations/rules as you create
 * those data?"*
 *
 * Yes, it did. These rows are written straight through Eloquent, so nothing
 * on the way in checks that the SET makes sense — the API never sees them and
 * the workflow that would normally issue them is not run. A clearance exists
 * because a business was permitted to trade and then had its premises
 * inspected; one standing on its own describes a business the city never
 * licensed, and a tester reading that screen would be testing a state the
 * register cannot otherwise reach.
 *
 * So each business now holds all six: the Mayor's / Business Permit and the
 * five clearances. Only the dates differ, which is the only thing any of
 * these scenarios was ever about.
 *
 * ── Dates are relative to the day it runs ──────────────────────────────────
 *
 * A fixture pinned to 2026 silently stops testing anything in 2027. Each date
 * clears its boundary by a margin — 12 days inside a 30-day window, 120 days
 * outside it, 40 months past a 36-month cap — so a run on any day puts each
 * business on the same side of the same line.
 *
 * The Mayor's Permit always ends on a 20 January, because `RenewalSeason`
 * says every business permit does, and a demo one that ended on some other
 * date would be testing a term the system cannot issue.
 *
 * ── It writes to whatever database it is pointed at ────────────────────────
 *
 * Deliberately, unlike `SeedRenewalUiFixtures`, which refuses anything but
 * the E2E copy: the client tests by hand on the dev register, so that is
 * where the rows have to land. The safeguards are that every row carries the
 * same prefix so it can be found again, that `--fresh` removes only rows
 * carrying it, and that the counts are printed either side — "nothing was
 * lost" is a measurement (AGENTS.md §2.2).
 */
class SeedExpiringDemoBusinesses extends Command
{
    protected $signature = 'biztrack:seed-expiring-demo
                            {--owner=owner@biztrack.local}
                            {--fresh : Delete the businesses this command made before seeding again}';

    protected $description = 'Seed five businesses, each holding all six permits, at the five renewal-window states.';

    /** Every row this command writes carries it, so they can all be found again. */
    private const PREFIX = '[DEMO] ';

    /** The five clearances, in the order the picker lists them. */
    private const CLEARANCES = ['SANITARY', 'FSIC', 'OCCUPANCY', 'CEC', 'ZONING'];

    public function handle(): int
    {
        $owner = User::where('email', (string) $this->option('owner'))->first();
        if ($owner === null) {
            $this->error('No such user: '.$this->option('owner'));

            return self::FAILURE;
        }

        $barangayId = Barangay::value('id');
        if ($barangayId === null) {
            $this->error('No barangays in this database — run the reference seeder first.');

            return self::FAILURE;
        }

        $before = ['businesses' => Business::count(), 'permits' => Permit::count()];
        $this->line("Before: {$before['businesses']} businesses, {$before['permits']} permits");

        if ($this->option('fresh')) {
            $this->removeOwnRows();
        }

        $now = CarbonImmutable::now();

        /*
         * `due` places ONE permit on its boundary; everything else on the
         * business is plainly in force. A code absent from `due` gets the
         * default below — comfortably valid, and far enough out that it falls
         * in the "Still valid" group rather than near any edge.
         */
        $plan = [
            [
                'name' => 'Dampalit Sari-Sari Store',
                'note' => 'Sanitary Permit expires in 12 days — inside the 30-day window, renewable today.',
                'due' => ['SANITARY' => $now->addDays(12)],
            ],
            [
                'name' => 'Catmon Bakeshop',
                'note' => 'Nothing due at all — every permit in force, so the whole "Still valid" group shows.',
                'due' => [],
            ],
            [
                'name' => 'Longos Hardware',
                'note' => 'FSIC lapsed 4 months ago — renewable, and billed the 25% + 2%/month surcharge.',
                'due' => ['FSIC' => $now->subMonths(4)],
            ],
            [
                'name' => 'Tinajeros Water Refilling',
                'note' => 'Occupancy Permit lapsed 40 months ago — past the 36-month cap, must file a New Application.',
                'due' => ['OCCUPANCY' => $now->subMonths(40)],
            ],
            [
                'name' => 'Maysilo Printing Press',
                /*
                 * The one that makes the BUSINESS PERMIT testable at all.
                 *
                 * Every other business here holds a Mayor's Permit running
                 * to the next 20 January, so for eleven months of the year
                 * none of them can renew it and the whole path — the form,
                 * the Tax Order of Payment, the clearance stage — cannot be
                 * walked by hand until January.
                 *
                 * This one's term ended at the LAST 20 January, so it is
                 * already late: renewable today, and surcharged under Secs.
                 * 8A.04/8A.05 by `WorkflowService::latePenaltyFor`. Lateness
                 * is the only state of a business permit renewal that can
                 * be reached outside January, so it is the only one a
                 * tester can exercise before then.
                 */
                'note' => 'Mayor’s Permit term ended last 20 January — renewable NOW, with the late surcharge. The only way to walk the business permit renewal before January.',
                'due' => [PermitType::OUTCOME_CODE => self::lastTwentiethOfJanuary($now)],
            ],
            [
                'name' => 'Potrero Carinderia',
                /*
                 * Two rules on one screen: the Mayor's Permit locked until
                 * January, and a clearance that is due now. Worth having
                 * together, because the two refusals are worded differently
                 * and a tester should see that they do not collide.
                 */
                'note' => 'Mayor’s Permit locked until 1 January; Sanitary Permit due in 20 days. Both rules on one screen.',
                'due' => ['SANITARY' => $now->addDays(20)],
            ],
        ];

        $made = 0;
        foreach ($plan as $spec) {
            $name = self::PREFIX.$spec['name'];

            if (Business::where('name', $name)->exists()) {
                $this->line("  skip   {$name} (already present — use --fresh to rebuild)");

                continue;
            }

            $business = Business::create([
                'owner_user_id' => $owner->id,
                'name' => $name,
                'registration_type' => 'DTI',
                'barangay_id' => $barangayId,
                'address_line' => 'Demo address, Malabon',
                'status' => 'active',
            ]);

            /*
             * The address row, which the first version of this command did
             * not write — and `business.address` is a `hasOne`, so it was
             * null. The renewal wizard reads a dozen fields straight through
             * it when a business is chosen, so pressing Continue threw inside
             * a state updater and unmounted the whole page: a blank screen,
             * no message, no nav (client, 4 October 2026).
             *
             * The wizard is guarded now and treats a missing address as empty
             * boxes. This still writes one, for the same reason every business
             * here holds all six permits: demo data that cannot exist in the
             * register is testing a state the product never has to handle.
             * A real business always has an address — `BusinessController`
             * requires it at registration.
             */
            BusinessAddress::create([
                'business_id' => $business->id,
                'house_bldg_no' => '12',
                'street' => 'Demo Street',
                'line1' => '12 Demo Street',
                'barangay_id' => $barangayId,
                'city' => 'Malabon',
                'province' => 'Metro Manila',
                'telephone' => '8281 4999',
                'mobile_number' => '+639171234567',
                'email' => $owner->email,
                /* Malabon City Hall, so the map pin lands in the city. */
                'latitude' => 14.6570,
                'longitude' => 120.9567,
            ]);

            /*
             * `permits.application_id` is NOT NULL — every certificate the
             * register holds was issued by some filing — so each business
             * needs the approved application its permits came from, even
             * though nothing here reads it back.
             */
            $priorApp = Application::create([
                'business_id' => $business->id,
                'applicant_user_id' => $owner->id,
                'application_type' => 'new',
                'status' => 'approved',
            ]);

            /*
             * The Mayor's Permit first, because it is the one that makes the
             * rest legitimate: a clearance is issued to a business the city
             * has licensed, and a shop holding five of them with no business
             * permit is a state the register cannot otherwise reach.
             */
            $this->issue(
                $business,
                $priorApp,
                PermitType::OUTCOME_CODE,
                $spec['due'][PermitType::OUTCOME_CODE] ?? self::nextTwentiethOfJanuary($now),
            );

            foreach (self::CLEARANCES as $code) {
                $this->issue(
                    $business,
                    $priorApp,
                    $code,
                    /* Default: in force, and well clear of the 30-day window. */
                    $spec['due'][$code] ?? $now->addMonths(11),
                );
            }

            $made++;
            $this->info("  made   {$name}");
            $this->line("         {$spec['note']}");
        }

        $after = ['businesses' => Business::count(), 'permits' => Permit::count()];
        $this->newLine();
        $this->line("After:  {$after['businesses']} businesses, {$after['permits']} permits");
        $this->line(sprintf(
            'Change: %+d businesses, %+d permits. %d built, each holding all six permits.',
            $after['businesses'] - $before['businesses'],
            $after['permits'] - $before['permits'],
            $made,
        ));
        $this->newLine();
        $this->line('All named "'.self::PREFIX.'…" and owned by '.$owner->email.'.');

        return self::SUCCESS;
    }

    /** One certificate, with the year-long term a renewal would have given it. */
    private function issue(
        Business $business,
        Application $application,
        string $code,
        CarbonImmutable $validUntil,
    ): void {
        $type = PermitType::where('code', $code)->first();
        if ($type === null) {
            $this->warn("  no permit type {$code} — skipped");

            return;
        }

        Permit::create([
            'application_id' => $application->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => 'DEMO-'.$code.'-'.$business->id,
            'issued_at' => $validUntil->subYear(),
            'valid_from' => $validUntil->subYear(),
            'valid_until' => $validUntil,
            /*
             * `active` even when the date is in the past. Expiry is a date in
             * this register, not a status — `ScanPermits` is what sweeps
             * lapsed ones, and pre-marking them would hide whether that sweep
             * works.
             */
            'status' => 'active',
        ]);
    }

    /**
     * Delete the businesses this command made, and nothing else.
     *
     * Matched on the prefix alone, so a real business can never be caught by
     * it. Permits and the carrier application go with the business, in that
     * order, because `permits.application_id` and `permits.business_id` are
     * both NOT NULL and a half-deleted set is worse than either state.
     */
    private function removeOwnRows(): void
    {
        $mine = Business::where('name', 'like', self::PREFIX.'%')->get();

        if ($mine->isEmpty()) {
            $this->line('  fresh  nothing of mine to remove');

            return;
        }

        $permits = 0;
        foreach ($mine as $business) {
            $permits += $business->permits()->count();
            $applicationIds = $business->applications()->pluck('id');

            $business->address()->delete();
            $business->permits()->delete();
            Application::whereIn('id', $applicationIds)->delete();
            $business->delete();
        }

        $this->line(sprintf('  fresh  removed %d businesses and %d permits', $mine->count(), $permits));
    }

    /**
     * The next 20 January strictly after today.
     *
     * Matches `RenewalSeason`: a business permit's term always ends on a 20
     * January, and which one decides the month this business may renew in.
     */
    /**
     * The most recent 20 January on or before today.
     *
     * A business permit whose term ended there is LATE, which is the only
     * state of one that can be renewed outside January — and so the only
     * state a tester can reach for most of the year.
     */
    private static function lastTwentiethOfJanuary(CarbonImmutable $from): CarbonImmutable
    {
        $thisYear = CarbonImmutable::create($from->year, 1, 20)->startOfDay();

        return $from->greaterThanOrEqualTo($thisYear)
            ? $thisYear
            : CarbonImmutable::create($from->year - 1, 1, 20)->startOfDay();
    }

    private static function nextTwentiethOfJanuary(CarbonImmutable $from): CarbonImmutable
    {
        $thisYear = CarbonImmutable::create($from->year, 1, 20)->startOfDay();

        return $from->lessThan($thisYear)
            ? $thisYear
            : CarbonImmutable::create($from->year + 1, 1, 20)->startOfDay();
    }
}
