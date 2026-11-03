<?php

namespace App\Support;

use App\Models\Barangay;

/**
 * Divides Malabon's barangays among an office's inspectors so that each one
 * covers a cluster of NEIGHBOURS [client, 27 October 2026: "pagsasamahin na
 * sa isang inspector kung ano ano ang magkakalapit na barangay"].
 *
 * ── How ─────────────────────────────────────────────────────────────────────
 *
 * Each barangay is reduced to the centre of its polygon (MalabonGeo's data,
 * the same outlines the maps draw). Seeds are spread as far apart as
 * possible, one per inspector; then the groups GROW in turns, each taking
 * the unplaced barangay nearest to the ones it already holds, until every
 * barangay is placed. Taking turns is what keeps the groups the same size,
 * and growing from what a group already holds is what keeps each one in
 * one piece — a plain nearest-centre split handed one inspector Dampalit in
 * the north-west and Potrero in the far east because both were "nearest"
 * to a centre that sat between them.
 *
 * Small and deterministic: 21 barangays, a handful of inspectors. The same
 * inspectors in the same order always get the same areas, so pressing the
 * button twice does not reshuffle the city.
 *
 * It PROPOSES. The office reads the proposal on screen and applies it, or
 * moves a barangay by hand; nothing here writes to the pool.
 */
class BarangayAreas
{
    /**
     * @param  list<int>  $inspectorIds
     * @return array<int, list<int>>  inspector id => barangay ids, every barangay placed once
     */
    public static function divide(array $inspectorIds): array
    {
        $inspectorIds = array_values(array_unique($inspectorIds));
        $k = count($inspectorIds);
        if ($k === 0) {
            return [];
        }

        $centres = self::centres();
        $ids = array_keys($centres);
        if ($ids === []) {
            return array_fill_keys($inspectorIds, []);
        }
        if ($k >= count($ids)) {
            // One barangay each; the rest of the inspectors get none.
            $out = array_fill_keys($inspectorIds, []);
            foreach ($ids as $i => $barangayId) {
                $out[$inspectorIds[$i]][] = $barangayId;
            }

            return $out;
        }

        // Farthest-first seeds, from the northernmost barangay.
        usort($ids, fn (int $a, int $b) => $centres[$b][0] <=> $centres[$a][0] ?: $a <=> $b);
        $groups = [[$ids[0]]];
        $placed = [$ids[0] => 0];
        while (count($groups) < $k) {
            $far = null;
            $farDist = -1.0;
            foreach ($ids as $id) {
                if (isset($placed[$id])) {
                    continue;
                }
                $nearest = min(array_map(fn (array $g) => self::dist2($centres[$g[0]], $centres[$id]), $groups));
                if ($nearest > $farDist) {
                    $farDist = $nearest;
                    $far = $id;
                }
            }
            $placed[$far] = count($groups);
            $groups[] = [$far];
        }

        // Grow in turns: each group takes the unplaced barangay nearest to
        // anything it already holds. A group whose nearest is far while
        // another's is near still takes its turn, which is the point.
        while (count($placed) < count($ids)) {
            foreach ($groups as $g => $members) {
                if (count($placed) >= count($ids)) {
                    break;
                }
                $best = null;
                $bestDist = INF;
                foreach ($ids as $id) {
                    if (isset($placed[$id])) {
                        continue;
                    }
                    $d = min(array_map(fn (int $m) => self::dist2($centres[$m], $centres[$id]), $members));
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best = $id;
                    }
                }
                $groups[$g][] = $best;
                $placed[$best] = $g;
            }
        }

        /*
         * Tidy up by swapping. Taking turns leaves the last picks as
         * leftovers — the north-west corner handed to the east's inspector
         * because the north's group was already full. Swapping two
         * barangays between two groups keeps every group the same size, so
         * every swap that brings each group closer together is taken,
         * best first, until none is left.
         */
        $centroid = fn (array $members) => [
            array_sum(array_map(fn (int $id) => $centres[$id][0], $members)) / count($members),
            array_sum(array_map(fn (int $id) => $centres[$id][1], $members)) / count($members),
        ];
        $spread = function (array $members) use ($centres, $centroid): float {
            $c = $centroid($members);

            return array_sum(array_map(fn (int $id) => self::dist2($c, $centres[$id]), $members));
        };
        for ($round = 0; $round < 100; $round++) {
            $bestGain = 0.0;
            $bestSwap = null;
            foreach ($groups as $ga => $ma) {
                foreach ($groups as $gb => $mb) {
                    if ($gb <= $ga) {
                        continue;
                    }
                    $before = $spread($ma) + $spread($mb);
                    foreach ($ma as $ia => $x) {
                        foreach ($mb as $ib => $y) {
                            $na = $ma;
                            $nb = $mb;
                            $na[$ia] = $y;
                            $nb[$ib] = $x;
                            $gain = $before - ($spread($na) + $spread($nb));
                            if ($gain > $bestGain + 1.0) {
                                $bestGain = $gain;
                                $bestSwap = [$ga, $ia, $gb, $ib];
                            }
                        }
                    }
                }
            }
            if ($bestSwap === null) {
                break;
            }
            [$ga, $ia, $gb, $ib] = $bestSwap;
            [$groups[$ga][$ia], $groups[$gb][$ib]] = [$groups[$gb][$ib], $groups[$ga][$ia]];
        }

        $seeds = array_map($centroid, $groups);

        // Groups to inspectors, north to south, so the order is stable.
        $groupOrder = array_keys($seeds);
        usort($groupOrder, fn (int $a, int $b) => $seeds[$b][0] <=> $seeds[$a][0]);
        $out = array_fill_keys($inspectorIds, []);
        foreach ($groupOrder as $slot => $g) {
            $members = $groups[$g];
            sort($members);
            $out[$inspectorIds[$slot]] = $members;
        }

        return $out;
    }

    /**
     * Barangay id => [lat, lng] of its polygon's centre, for every barangay
     * on the register that the geo data knows. Barangays the data does not
     * know (none, today) are left out and so unplaced.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public static function centres(): array
    {
        $byName = [];
        foreach (MalabonGeo::data()['barangays'] as $b) {
            $ring = $b['rings'][0] ?? [];
            if ($ring === []) {
                continue;
            }
            $lat = array_sum(array_map(fn (array $p) => $p[1], $ring)) / count($ring);
            $lng = array_sum(array_map(fn (array $p) => $p[0], $ring)) / count($ring);
            $byName[self::key($b['name'])] = [$lat, $lng];
        }

        $out = [];
        foreach (Barangay::query()->get(['id', 'name']) as $barangay) {
            $centre = $byName[self::key($barangay->name)] ?? null;
            if ($centre !== null) {
                $out[$barangay->id] = $centre;
            }
        }

        return $out;
    }

    /** Squared distance in metres, on the same flat approximation MalabonGeo uses. */
    public static function dist2(array $a, array $b): float
    {
        $dy = ($a[0] - $b[0]) * 110574.0;
        $dx = ($a[1] - $b[1]) * 111320.0 * 0.96744467;

        return $dx * $dx + $dy * $dy;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/[^a-z]/i', '', str_replace(['ñ', 'Ñ'], 'n', $name)) ?? '');
    }
}
