<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * "When did this last run?", for the background work nobody watches: the
 * scheduler, the queue worker and the payment reconciler. Each writes a beat
 * when it runs; the Debug page's Health section (SystemHealth) reads how old
 * the beat is.
 *
 * Kept in `settings` (`heartbeat.<name>`) rather than the cache because the
 * cache is the thing most likely to be cleared or per-process, and a beat that
 * vanished on `cache:clear` would read as the scheduler dying.
 *
 * Never throws. A beat that cannot be written (the database locked for a
 * moment) is logged and skipped: the work it reports on must not fail
 * because its own heartbeat could not be recorded.
 */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    public const RECONCILE = 'reconcile';

    /** @param  array<string, scalar|null>  $data  anything worth showing beside the time */
    public static function beat(string $name, array $data = []): void
    {
        try {
            Setting::write('heartbeat.'.$name, (string) json_encode(['at' => now()->toIso8601String()] + $data));
        } catch (\Throwable $e) {
            Log::warning("Heartbeat {$name} not recorded: ".$e->getMessage());
        }
    }

    /**
     * The last beat, with `at` as a time, or null if there has never been one.
     *
     * @return array{at: CarbonImmutable}&array<string, mixed>|null
     */
    public static function last(string $name): ?array
    {
        try {
            $raw = Setting::read('heartbeat.'.$name);
        } catch (\Throwable) {
            return null;
        }

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! isset($data['at'])) {
            return null;
        }

        try {
            $data['at'] = CarbonImmutable::parse((string) $data['at']);
        } catch (\Throwable) {
            return null;
        }

        return $data;
    }
}
