<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A precomputed set of statistics, as of `computed_at`.
 *
 * See the migration for why the table exists and why one row is one (dataset,
 * parameter combination). Read through App\Support\AnalyticsResolver rather than
 * querying this model from a controller — the resolver is what attaches the
 * freshness meta a screen is required to display.
 *
 * `engine_version` is a leftover of the removed R service: it holds R's version
 * on the rows R computed and null on everything written since. It stays fillable
 * so those historical rows still hydrate, and the refresher writes null into it.
 * Nothing reads it — see AnalyticsResolver on why the response reports `engine`
 * as a constant now.
 */
class AnalyticsSnapshot extends Model
{
    protected $fillable = [
        'key', 'dataset', 'payload', 'source', 'engine_version',
        'duration_ms', 'computed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'computed_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    /**
     * The lookup key for a dataset and its parameters.
     *
     * Parameters are sorted by name so the key is stable no matter which order a
     * caller happened to build the array in — the alternative is two snapshots
     * of the same thing, one of which never gets read.
     *
     * @param  array<string, int|string>  $params
     */
    public static function keyFor(string $dataset, array $params = []): string
    {
        ksort($params);

        $suffix = implode(',', array_map(
            static fn (string $name, int|string $value): string => "{$name}={$value}",
            array_keys($params),
            array_values($params),
        ));

        return $suffix === '' ? $dataset : "{$dataset}:{$suffix}";
    }

    /** Whether these figures are old enough that the screen should say so. */
    public function isStale(): bool
    {
        return $this->computed_at->lt(
            now()->subHours((int) config('analytics.stale_after_hours')),
        );
    }
}
