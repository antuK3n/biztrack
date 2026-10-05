<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move every date-time written under the UTC clock forward eight hours.
 *
 * ── Why ──────────────────────────────────────────────────────────────────────
 *
 * `config/app.php` ran on 'UTC' until 5 October 2026, so a filing submitted at
 * 9:16 PM in Malabon was stored as "13:16" and, worse, anything done between
 * midnight and 8 AM was DATED the previous day — the day a permit was issued,
 * the day a sheet was filed. The browser walk of the three flows found it on
 * every screen, and the clock moved to Asia/Manila that morning.
 *
 * Timestamps here carry no zone. Read under the new clock, every old value is
 * eight hours early. Client, 5 October 2026: *"Shift old times by +8 hours."*
 *
 * ── Which values ─────────────────────────────────────────────────────────────
 *
 * The clock changed at 2026-10-04 21:45:43 UTC (05:45:43 Manila). Values
 * written before that instant are UTC; values written after are Manila. The
 * two are told apart by the row:
 *
 *   - a row not touched since the switch (`updated_at` — or `created_at`
 *     where there is none — earlier than the cutoff) has every date-time
 *     column in UTC, future dates included, so all of them move;
 *   - a row touched since has mixed columns, so only a value that is itself
 *     earlier than the cutoff moves — such a value cannot have been written
 *     under the Manila clock, whose smallest possible stamp is cutoff + 8 h.
 *     A future date on such a row is left alone: it could be either, and a
 *     wrong guess there is worse than an eight-hour slip on a date nobody
 *     reads to the hour.
 *
 * Date-only columns (`date` type) are not touched: they were produced from
 * `toDateString()` on the UTC day and are off by a DAY on the early-morning
 * rows, not by hours — and only for those rows; there is no honest rule that
 * finds exactly them, and the client's instruction was the times.
 *
 * ── Irreversible by design ───────────────────────────────────────────────────
 *
 * `down()` does nothing: the rows this moved are not re-identifiable after
 * the fact, and the register is backed up to a file beside it before this
 * runs (`database.sqlite.bak-before-tz-shift-*`). Four rows had been written
 * under the new clock when it ran on the live register; everything else moved.
 */
return new class extends Migration
{
    private const CUTOFF = '2026-10-04 21:45:43';

    public function up(): void
    {
        $driver = DB::getDriverName();
        $plus8 = fn (string $col) => match ($driver) {
            'sqlite' => "datetime({$col}, '+8 hours')",
            'pgsql' => "{$col} + interval '8 hours'",
            default => "DATE_ADD({$col}, INTERVAL 8 HOUR)",
        };

        $report = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, ['migrations', 'sqlite_sequence'], true)) {
                continue;
            }
            $columns = collect(Schema::getColumns($name))
                ->filter(fn (array $c) => in_array(strtolower($c['type_name']), ['datetime', 'timestamp', 'timestamp without time zone'], true))
                ->pluck('name')
                ->values();
            if ($columns->isEmpty()) {
                continue;
            }

            $discriminator = $columns->contains('updated_at') ? 'updated_at'
                : ($columns->contains('created_at') ? 'created_at' : null);
            // The discriminator moves LAST: once it has moved, a row stamped in
            // the eight hours before the switch would read as touched since.
            $columns = $columns->sortBy(fn (string $c) => $c === $discriminator ? 1 : 0)->values();

            $moved = 0;
            foreach ($columns as $col) {
                if ($discriminator !== null) {
                    // Untouched since the switch: every column is UTC.
                    $moved += DB::table($name)
                        ->whereNotNull($col)
                        ->where($discriminator, '<', self::CUTOFF)
                        ->update([$col => DB::raw($plus8($col))]);
                    // Touched since: only values that predate the switch.
                    $moved += DB::table($name)
                        ->whereNotNull($col)
                        ->where($discriminator, '>=', self::CUTOFF)
                        ->where($col, '<', self::CUTOFF)
                        ->update([$col => DB::raw($plus8($col))]);
                } else {
                    $moved += DB::table($name)
                        ->whereNotNull($col)
                        ->where($col, '<', self::CUTOFF)
                        ->update([$col => DB::raw($plus8($col))]);
                }
            }
            if ($moved > 0) {
                $report[] = "{$name}: {$moved} values";
            }
        }

        // Migrations have no console of their own; the operator reads the log.
        logger()->info('tz shift +8h applied', ['moved' => $report]);
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "tz shift +8h: ".implode('; ', $report).PHP_EOL);
        }
    }

    public function down(): void
    {
        // Not reversible — see the header. Restore the backup instead.
    }
};
