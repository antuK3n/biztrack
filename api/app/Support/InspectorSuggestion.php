<?php

namespace App\Support;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\Inspector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Who should take a visit, and when somebody can [client, 27 October 2026:
 * "automate (or suggest) inspector assignment based on inspector
 * availability"].
 *
 * ── The rule, in the order it is applied ───────────────────────────────────
 *
 *   1. Only inspectors who are ACTIVE, WORK that weekday, are NOT ON LEAVE
 *      that day and still have ROOM (visits booked that day < their maximum)
 *      are available.
 *   2. Among the available, one who COVERS the business's barangay comes
 *      first; failing that, the one whose area is NEAREST to it (by the
 *      distance between barangay centres), so a visit in an uncovered
 *      barangay still goes to whoever is closest rather than to nobody.
 *   3. Ties go to the LIGHTEST day — fewest visits already booked — then to
 *      the fewest visits in the week, then to the name.
 *
 * Every candidate is returned with the reasons it was or was not chosen, so
 * the officer booking the visit sees why the suggestion is what it is and
 * can overrule it with the facts in front of them. A suggestion that cannot
 * explain itself is one nobody trusts twice.
 *
 * When nobody is available on the asked day, `next_available` names the
 * first working day (within the next twenty) on which somebody covering the
 * barangay has room, with who.
 */
class InspectorSuggestion
{
    /** Working days looked ahead for `next_available`. */
    private const LOOKAHEAD_DAYS = 20;

    /**
     * @return array{
     *   suggested: ?array<string, mixed>,
     *   candidates: list<array<string, mixed>>,
     *   next_available: ?array{date: string, inspector: array<string, mixed>},
     *   barangay: ?array{id: int, name: string},
     *   pool_size: int
     * }
     */
    public static function forVisit(int $departmentId, ?int $barangayId, CarbonInterface $day, ?int $exceptInspectionId = null): array
    {
        $day = CarbonImmutable::instance($day)->setTimezone(config('app.timezone'))->startOfDay();
        $pool = self::pool($departmentId);
        $centres = BarangayAreas::centres();
        $barangayName = $barangayId ? \App\Models\Barangay::whereKey($barangayId)->value('name') : null;

        $loadsDay = self::loads($pool, $day, $day, $exceptInspectionId);
        $loadsWeek = self::loads($pool, $day->startOfWeek(), $day->endOfWeek(), $exceptInspectionId);

        $candidates = $pool->map(function (Inspector $inspector) use ($barangayId, $day, $loadsDay, $loadsWeek, $centres) {
            $reasons = [];
            $available = true;

            if (! $inspector->worksOn($day)) {
                $available = false;
                $reasons[] = 'Not working on '.$day->format('l').'s';
            }
            if ($leave = $inspector->onLeave($day)) {
                $available = false;
                $reasons[] = 'On leave'.($leave->reason ? " — {$leave->reason}" : '')
                    .' until '.$leave->ends_on->format('M j');
            }
            $booked = $loadsDay[$inspector->id] ?? 0;
            if ($booked >= $inspector->max_visits_per_day) {
                $available = false;
                $reasons[] = "Full that day ({$booked} of {$inspector->max_visits_per_day} visits)";
            }

            $covers = $barangayId !== null && $inspector->covers($barangayId);
            $distance = null;
            if (! $covers && $barangayId !== null && isset($centres[$barangayId])) {
                $own = $inspector->barangays->pluck('id')->filter(fn (int $id) => isset($centres[$id]));
                if ($own->isNotEmpty()) {
                    $distance = $own->map(fn (int $id) => sqrt(BarangayAreas::dist2($centres[$id], $centres[$barangayId])))->min();
                }
            }

            return [
                'id' => $inspector->id,
                'name' => $inspector->name,
                'mobile_number' => $inspector->mobile_number,
                'available' => $available,
                'covers_barangay' => $covers,
                // Metres from the nearest barangay in their area, when they do not cover it.
                'distance_m' => $distance !== null ? (int) round($distance) : null,
                'booked_that_day' => $booked,
                'max_visits_per_day' => $inspector->max_visits_per_day,
                'booked_that_week' => $loadsWeek[$inspector->id] ?? 0,
                'barangays' => $inspector->barangays->pluck('name')->sort()->values()->all(),
                'reasons' => $reasons,
            ];
        });

        $ranked = $candidates->sortBy([
            fn (array $a, array $b) => (int) $b['available'] <=> (int) $a['available'],
            fn (array $a, array $b) => (int) $b['covers_barangay'] <=> (int) $a['covers_barangay'],
            fn (array $a, array $b) => ($a['distance_m'] ?? PHP_INT_MAX) <=> ($b['distance_m'] ?? PHP_INT_MAX),
            fn (array $a, array $b) => $a['booked_that_day'] <=> $b['booked_that_day'],
            fn (array $a, array $b) => $a['booked_that_week'] <=> $b['booked_that_week'],
            fn (array $a, array $b) => strcmp($a['name'], $b['name']),
        ])->values();

        $suggested = $ranked->first(fn (array $c) => $c['available']);
        if ($suggested !== null) {
            $suggested['why'] = self::why($suggested, $barangayName);
        }

        return [
            'suggested' => $suggested,
            'candidates' => $ranked->map(function (array $c) use ($barangayName) {
                $c['why'] = $c['available'] ? self::why($c, $barangayName) : implode('; ', $c['reasons']);

                return $c;
            })->all(),
            'next_available' => $suggested === null && $pool->isNotEmpty()
                ? self::nextAvailable($departmentId, $barangayId, $day->addDay(), $exceptInspectionId)
                : null,
            'barangay' => $barangayId && $barangayName ? ['id' => $barangayId, 'name' => $barangayName] : null,
            'pool_size' => $pool->count(),
        ];
    }

    /** The first working day on which somebody is available, looking ahead. */
    public static function nextAvailable(int $departmentId, ?int $barangayId, CarbonImmutable $from, ?int $exceptInspectionId = null): ?array
    {
        $day = $from->startOfDay();
        for ($i = 0; $i < self::LOOKAHEAD_DAYS; $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $i++;
            $answer = self::forVisit($departmentId, $barangayId, $day, $exceptInspectionId);
            if ($answer['suggested'] !== null) {
                return ['date' => $day->toDateString(), 'inspector' => $answer['suggested']];
            }
        }

        return null;
    }

    /** @return Collection<int, Inspector> */
    /**
     * Why this inspector cannot take a visit on that day, in words, or null
     * when they can: the same three tests the ranking above applies (works
     * that weekday, not on leave, has room), asked of one inspector so a
     * date or an inspector picked by hand is held to them too [client,
     * 3 November 2026: "kung sa manually may validations … day and time and
     * availability"]. `$except` leaves the visit being moved out of the count.
     */
    public static function whyNotFree(Inspector $inspector, CarbonInterface $day, ?int $except = null): ?string
    {
        $day = CarbonImmutable::instance($day)->setTimezone(config('app.timezone'))->startOfDay();
        $inspector->loadMissing('leaves');
        $label = $day->format('l, F j');

        if (! $inspector->worksOn($day)) {
            return "{$inspector->name} does not inspect on {$day->format('l')}s. Pick another day or another inspector.";
        }
        if ($leave = $inspector->onLeave($day)) {
            return "{$inspector->name} is on leave on {$label}"
                .($leave->reason ? " ({$leave->reason})" : '').'. Pick another day or another inspector.';
        }
        $booked = self::loads(collect([$inspector]), $day, $day, $except)[$inspector->id] ?? 0;
        if ($booked >= $inspector->max_visits_per_day) {
            return "{$inspector->name} already has {$booked} of {$inspector->max_visits_per_day} visits on {$label}. "
                .'Pick another day or another inspector.';
        }

        return null;
    }

    private static function pool(int $departmentId): Collection
    {
        return Inspector::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->with(['barangays:id,name', 'leaves'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Visits each inspector holds between two instants, by inspector id.
     * Open visits only: a completed or cancelled one takes no time that day.
     *
     * @param  Collection<int, Inspector>  $pool
     * @return array<int, int>
     */
    private static function loads(Collection $pool, CarbonImmutable $from, CarbonImmutable $to, ?int $except): array
    {
        if ($pool->isEmpty()) {
            return [];
        }

        return Inspection::query()
            ->whereIn('inspector_id', $pool->pluck('id'))
            ->whereIn('status', [InspectionStatus::Scheduled, InspectionStatus::Rescheduled, InspectionStatus::InProgress])
            ->whereBetween('scheduled_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->selectRaw('inspector_id, COUNT(*) as n')
            ->groupBy('inspector_id')
            ->pluck('n', 'inspector_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private static function why(array $c, ?string $barangay): string
    {
        $parts = [];
        if ($c['covers_barangay'] && $barangay) {
            $parts[] = "Covers {$barangay}";
        } elseif ($c['distance_m'] !== null && $barangay) {
            $km = $c['distance_m'] >= 1000 ? round($c['distance_m'] / 1000, 1).' km' : $c['distance_m'].' m';
            $parts[] = "Nearest area to {$barangay} ({$km} away)";
        } elseif ($barangay) {
            $parts[] = 'No area set';
        }
        $parts[] = "{$c['booked_that_day']} of {$c['max_visits_per_day']} visits that day";

        return implode(' · ', $parts);
    }
}
