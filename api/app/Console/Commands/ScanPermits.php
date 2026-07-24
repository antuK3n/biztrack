<?php

namespace App\Console\Commands;

use App\Enums\PermitStatus;
use App\Models\Permit;
use App\Models\PermitExpiryNotice;
use App\Services\NotificationService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Nightly permit-expiry scan (master plan S6). Idempotent: a per-(permit, kind)
 * ledger (permit_expiry_notices) prevents double-notifying across runs.
 *   1. Notify owners at 60/30/7 days before valid_until.
 *   2. Flip past-due active permits → expired (+ notify).
 *   3. Renewal-due nudge for permits that expired within the last 30 days.
 */
class ScanPermits extends Command
{
    protected $signature = 'biztrack:scan-permits';

    protected $description = 'Notify owners of expiring permits, auto-expire past-due ones, and nudge renewals.';

    public function handle(NotificationService $notify): int
    {
        $today = Carbon::now()->startOfDay();
        $thresholds = [60, 30, 7];
        $expiringNotices = 0;
        $expired = 0;
        $renewalNudges = 0;

        // --- 1. Upcoming-expiry reminders (active permits only) --------------
        foreach ($thresholds as $days) {
            $target = $today->copy()->addDays($days)->toDateString();
            $permits = Permit::where('status', PermitStatus::Active->value)
                ->whereDate('valid_until', $target)
                ->with('business.owner')
                ->get();

            foreach ($permits as $permit) {
                if ($this->markOnce($permit, "threshold_{$days}")) {
                    $notify->permitExpiring($permit, $days);
                    $expiringNotices++;
                }
            }
        }

        // --- 2. Auto-expire past-due active permits --------------------------
        $overdue = Permit::where('status', PermitStatus::Active->value)
            ->whereDate('valid_until', '<', $today->toDateString())
            ->with('business.owner')
            ->get();

        foreach ($overdue as $permit) {
            $permit->update(['status' => PermitStatus::Expired]);
            Audit::log('permit.expired', $permit);
            if ($this->markOnce($permit, 'expired')) {
                $notify->permitExpired($permit);
                $expired++;
            }
        }

        // --- 3. Renewal-due nudge (expired within the last 30 days) ----------
        $recentlyExpired = Permit::where('status', PermitStatus::Expired->value)
            ->whereDate('valid_until', '>=', $today->copy()->subDays(30)->toDateString())
            ->whereDate('valid_until', '<', $today->toDateString())
            ->with('business.owner')
            ->get();

        foreach ($recentlyExpired as $permit) {
            if ($this->markOnce($permit, 'renewal_due')) {
                $notify->renewalDue($permit);
                $renewalNudges++;
            }
        }

        $this->info("Scan complete: {$expiringNotices} expiry reminder(s), {$expired} auto-expired, {$renewalNudges} renewal nudge(s).");

        return self::SUCCESS;
    }

    /** Insert a dedupe ledger row; returns true only the first time per kind. */
    private function markOnce(Permit $permit, string $kind): bool
    {
        return (bool) PermitExpiryNotice::firstOrCreate([
            'permit_id' => $permit->id,
            'notice_kind' => $kind,
        ])->wasRecentlyCreated;
    }
}
