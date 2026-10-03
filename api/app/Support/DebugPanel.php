<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Whether the Debug page is open, and to whom: the ONE place that decides.
 *
 * The Debug page (/admin/debug on the web, /api/v1/debug/* on the API) holds
 * on-the-fly controls for the thesis defense — which way owners pay, what
 * KwikPay collects, and whatever else gets added there [Ken, 2026-10-04].
 * Controls that change a running register do not belong on a server by
 * default, so two things must both be true:
 *
 *   1. the caller is the super admin (role `admin`), and
 *   2. the panel is OPEN: a `debug_panel_until` row in `settings` holds a time
 *      still in the future.
 *
 * The middleware (EnsureDebugPanelOpen, alias `debug.panel`) asks allows();
 * the signed-in user's payload carries the same answer as `debug_panel`, which
 * is all the web app reads to show the rail entry and the route. Nothing else
 * may decide it, so tightening the rule is a change here and nowhere else.
 *
 * ── Opened from the server only, and closes itself ─────────────────────────
 *
 * `php artisan biztrack:debug-panel on --hours=6` writes the expiry; `off`
 * clears it. There is no endpoint that opens it: a stolen super-admin session
 * must not be able to switch the panel on. The expiry is the point — the
 * panel shuts after the defense even if nobody remembers to — so `on` is
 * capped at MAX_HOURS rather than left open-ended.
 *
 * ── Local development does not need the flag ───────────────────────────────
 *
 * With APP_ENV=local the flag is not consulted and the super admin always
 * has the page [Ken, 2026-10-04]. Only `local`: `testing` still needs it, so
 * the gate itself is what the tests exercise. A server that runs with
 * APP_ENV=local has the page open to its super admin permanently; that is a
 * reason to run the presented server as something other than local.
 */
class DebugPanel
{
    private const KEY = 'debug_panel_until';

    /** The longest `on` may hold the panel open. A defense is an afternoon. */
    public const MAX_HOURS = 24;

    public const DEFAULT_HOURS = 6;

    /** Super admin, and the panel open. Everything else is a 404. */
    public static function allows(?User $user): bool
    {
        return $user !== null && $user->hasRole('admin') && self::isOpen();
    }

    public static function isOpen(): bool
    {
        return self::bypassed() || self::openUntil() !== null;
    }

    /** APP_ENV=local: the flag is not needed. */
    public static function bypassed(): bool
    {
        return app()->environment('local');
    }

    /** When the flag closes, or null when it is not open (never set, cleared, or past). */
    public static function openUntil(): ?CarbonImmutable
    {
        $until = self::stored();
        if ($until === null || $until->lte(now())) {
            return null;
        }

        return $until;
    }

    /**
     * Open the panel for `$hours` from now and audit it. Returns when it closes.
     *
     * @throws \InvalidArgumentException outside 1–MAX_HOURS
     */
    public static function open(int $hours, string $via = 'artisan'): CarbonImmutable
    {
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new \InvalidArgumentException('Open it for 1 to '.self::MAX_HOURS.' hours.');
        }

        $before = self::openUntil();
        $until = CarbonImmutable::now()->addHours($hours);
        Setting::write(self::KEY, $until->toIso8601String());
        self::audit('panel_opened', ['open_until' => $before?->toIso8601String()], ['open_until' => $until->toIso8601String()], extra: ['via' => $via, 'hours' => $hours]);

        return $until;
    }

    /** Close it now and audit it. Closing a closed panel is still recorded. */
    public static function close(string $via = 'artisan'): void
    {
        $before = self::openUntil();
        Setting::write(self::KEY, null);
        self::audit('panel_closed', ['open_until' => $before?->toIso8601String()], ['open_until' => null], extra: ['via' => $via]);
    }

    /**
     * The audit trail for everything done through the panel: `debug.<action>`,
     * what it was before and after, and who. Every debug action writes exactly
     * one of these, so "what was changed from the Debug page during the
     * defense" is one filter on the audit log.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $extra
     */
    public static function audit(
        string $action,
        array $before,
        array $after,
        ?Model $subject = null,
        ?int $actorId = null,
        array $extra = [],
    ): void {
        Audit::log('debug.'.$action, $subject, ['before' => $before, 'after' => $after] + $extra, actorId: $actorId);
    }

    /*
     * Null when the row is missing, unreadable, or the settings table is not
     * there yet: a server a migration behind has the panel closed, which is
     * the safe way to be wrong.
     */
    private static function stored(): ?CarbonImmutable
    {
        try {
            $value = Setting::read(self::KEY);
        } catch (QueryException) {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
