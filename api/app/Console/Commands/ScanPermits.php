<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Permit;
use App\Models\PermitExpiryNotice;
use App\Services\NotificationService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Nightly permit-expiry scan (master plan S6; R integration spec §3).
 *
 *   1. Renewal reminders at 30 / 15 / 7 / 1 day before `valid_until`.
 *   2. Flip past-due active permits → expired (+ notify).
 *   3. Renewal-due nudge for permits that lapsed within the last 30 days.
 *
 * Two properties this command has to hold, because it runs unattended every
 * night against a register of thousands of permits:
 *
 * **Idempotent.** Every send is preceded by an insert into `permit_expiry_notices`
 * keyed on (permit_id, notice_kind), which carries a unique index. The insert is
 * the permission to send: if the row already exists the send is skipped. So a
 * second run on the same day is a no-op, and the 30-day reminder is not re-sent
 * for thirty consecutive nights. The ledger is also what "Reminders Sent" on the
 * Renewal Risk screen counts, so a row must never be written for a reminder that
 * was not actually delivered.
 *
 * **Bucketed, not exact-date.** The obvious implementation looks for permits
 * whose `valid_until` is exactly today + 30. That silently loses reminders: one
 * missed night, one deploy window, or a register seeded with history the scan has
 * never seen, and a permit passes 30 days without anybody being told. Instead
 * each permit is placed in the tightest threshold bucket it currently falls in
 * (22 days remaining → the 30-day bucket) and that bucket fires once. As the
 * permit ages the bucket steps down 30 → 15 → 7 → 1, one reminder each.
 *
 * A consequence worth being explicit about: buckets already passed when a permit
 * first comes into view never fire retroactively. A permit with 3 days left gets
 * the 7-day reminder and then the 1-day one — it is never told "expires in 30
 * days", because that would be false. The ledger therefore records what was sent,
 * not what was theoretically due.
 */
class ScanPermits extends Command
{
    protected $signature = 'biztrack:scan-permits';

    protected $description = 'Notify owners of expiring permits, auto-expire past-due ones, and nudge renewals.';

    /**
     * Reminder thresholds in days before expiry, widest first (R integration
     * spec §2/§3: "30, 15 and 7 and 1 day before expiry").
     *
     * @var list<int>
     */
    private const THRESHOLDS = [30, 15, 7, 1];

    /**
     * How long after expiry a post-expiry notice is still worth sending.
     *
     * This bound is the answer to "should historical permits generate reminders?".
     * The seeded register holds thousands of permits that lapsed months or years
     * ago. Telling an owner today that a 2024 permit "lapsed recently" is false,
     * and a backfill of them would put thousands of unactionable messages into
     * real feeds while inflating "Reminders Sent" with sends nobody could act on.
     * Past this window the scan corrects the permit's status silently and says
     * nothing.
     */
    private const LAPSED_NOTICE_DAYS = 30;

    /**
     * How long a permit must have been lapsed before the renewal nudge fires.
     *
     * Without a floor the "has expired" notice and the "renew now" nudge land on
     * the same night saying nearly the same thing. The gap makes them an
     * escalation — you were told it lapsed, and a week later you still have not
     * renewed — instead of a duplicate.
     */
    private const RENEWAL_NUDGE_AFTER_DAYS = 7;

    public function handle(NotificationService $notify): int
    {
        $today = Carbon::now()->startOfDay();
        $reminders = 0;
        $expired = 0;
        $renewalNudges = 0;

        // --- 1. Pre-expiry renewal reminders --------------------------------
        // One query for the whole reminder horizon, then bucket in PHP. The
        // widest threshold bounds the horizon, so nothing outside 30 days is
        // even loaded.
        $horizon = $today->copy()->addDays(self::THRESHOLDS[0])->toDateString();

        $upcoming = $this->notifiable()
            ->where('status', PermitStatus::Active->value)
            ->whereDate('valid_until', '>=', $today->toDateString())
            ->whereDate('valid_until', '<=', $horizon)
            ->get();

        $renewed = $this->permitsWithRenewalFiled($upcoming->pluck('id')->all());

        foreach ($upcoming as $permit) {
            // Spec §3: reminders fire "only for permits not yet renewed". A
            // business that has already filed is being processed, not ignored,
            // and chasing it would read as the system not knowing its own state.
            if (isset($renewed[$permit->id])) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays(Carbon::parse($permit->valid_until)->startOfDay(), false);
            $threshold = $this->bucketFor($daysLeft);
            if ($threshold === null) {
                continue;
            }

            if ($this->markOnce($permit, "threshold_{$threshold}")) {
                $notify->permitExpiring($permit, $threshold, $daysLeft);
                $reminders++;
            }
        }

        // --- 2. Auto-expire past-due active permits --------------------------
        // Not filtered by notifiability: correcting a stale `active` status is
        // bookkeeping the register needs whether or not anyone is told.
        $overdue = Permit::where('status', PermitStatus::Active->value)
            ->whereDate('valid_until', '<', $today->toDateString())
            ->with('business.owner')
            ->get();

        $overdueRenewed = $this->permitsWithRenewalFiled($overdue->pluck('id')->all());
        $lapsedNoticeFloor = $today->copy()->subDays(self::LAPSED_NOTICE_DAYS)->startOfDay();

        foreach ($overdue as $permit) {
            // The status flip is a fact about the permit and happens either way.
            $permit->update(['status' => PermitStatus::Expired]);
            Audit::log('permit.expired', $permit);

            // Long-lapsed history: correct the record, send nothing.
            if (Carbon::parse($permit->valid_until)->startOfDay()->lessThan($lapsedNoticeFloor)) {
                continue;
            }

            // Closed or unclaimed business: same reasoning as notifiable().
            if (! $permit->business?->owner) {
                continue;
            }

            // The notice attached to it says "please file a renewal", which is
            // wrong to send to someone whose renewal is already filed. No send,
            // no ledger row — the row means a message went out.
            if (isset($overdueRenewed[$permit->id])) {
                continue;
            }

            if ($this->markOnce($permit, 'expired')) {
                $notify->permitExpired($permit);
                $expired++;
            }
        }

        // --- 3. Renewal-due nudge (lapsed 7 to 30 days ago) ------------------
        $recentlyExpired = $this->notifiable()
            ->where('status', PermitStatus::Expired->value)
            ->whereDate('valid_until', '>=', $lapsedNoticeFloor->toDateString())
            ->whereDate('valid_until', '<=', $today->copy()->subDays(self::RENEWAL_NUDGE_AFTER_DAYS)->toDateString())
            ->get();

        $lapsedRenewed = $this->permitsWithRenewalFiled($recentlyExpired->pluck('id')->all());

        foreach ($recentlyExpired as $permit) {
            if (isset($lapsedRenewed[$permit->id])) {
                continue;
            }

            if ($this->markOnce($permit, 'renewal_due')) {
                $notify->renewalDue($permit);
                $renewalNudges++;
            }
        }

        $this->info("Scan complete: {$reminders} expiry reminder(s), {$expired} auto-expired, {$renewalNudges} renewal nudge(s).");

        return self::SUCCESS;
    }

    /**
     * Permits there is actually somebody to remind.
     *
     * Two exclusions, both learned the hard way — the first run of this scan
     * wrote 15 ledger rows for which no notification could be delivered, which
     * would have over-stated "Reminders Sent" by 15:
     *
     * - **Closed businesses.** `Business` soft-deletes, so `$permit->business`
     *   resolves to null for a closed one and the notification silently goes
     *   nowhere. Nobody should be chased to renew a permit for a business they
     *   have shut. RenewalRiskAnalytics excludes them from the watchlist for the
     *   same reason, so including them here would also mean sending reminders
     *   about permits the officer's screen does not show.
     * - **Unclaimed businesses.** No owner account, no inbox.
     *
     * Filtering in the query rather than bailing out mid-loop is what keeps the
     * ledger honest: `markOnce` is the permission to send, so it must not run for
     * a permit that cannot be sent to.
     */
    private function notifiable(): Builder
    {
        return Permit::query()
            ->whereHas('business', fn ($q) => $q->whereNotNull('owner_user_id'))
            ->with('business.owner');
    }

    /**
     * The tightest reminder threshold a permit with `$daysLeft` to run falls in,
     * or null if it is further out than the widest one.
     *
     * 30 → the 30-day bucket; 22 → also the 30-day bucket (nothing tighter has
     * been reached yet); 3 → the 7-day bucket; 0 → the 1-day bucket, since a
     * permit expiring today is at least as urgent as one expiring tomorrow.
     */
    private function bucketFor(int $daysLeft): ?int
    {
        if ($daysLeft > self::THRESHOLDS[0]) {
            return null;
        }

        $bucket = null;
        foreach (self::THRESHOLDS as $threshold) {
            if ($daysLeft <= $threshold) {
                $bucket = $threshold;
            }
        }

        return $bucket;
    }

    /**
     * Permits that already have a renewal standing against them, as a set.
     *
     * "Filed" means a renewal application exists against this permit, was
     * actually submitted, and has not been rejected or cancelled. A draft is not
     * a filing — it is an intention — so a business that started a renewal and
     * abandoned it still gets chased, which is the point of the reminder. A
     * rejected or cancelled filing leaves the permit exactly as un-renewed as
     * never having filed at all.
     *
     * @param  list<int>  $permitIds
     * @return array<int, true>
     */
    private function permitsWithRenewalFiled(array $permitIds): array
    {
        if ($permitIds === []) {
            return [];
        }

        $ids = DB::table('applications')
            ->whereNull('deleted_at')
            ->where('application_type', ApplicationType::Renewal->value)
            ->whereNotNull('submitted_at')
            ->whereNotIn('status', [
                ApplicationStatus::Rejected->value,
                ApplicationStatus::Cancelled->value,
            ])
            ->whereIn('prior_permit_id', $permitIds)
            ->distinct()
            ->pluck('prior_permit_id');

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * Claim the right to send one notice. Returns true only the first time per
     * (permit, kind); the unique index on the ledger makes that hold even if two
     * scans somehow overlap.
     */
    private function markOnce(Permit $permit, string $kind): bool
    {
        return (bool) PermitExpiryNotice::firstOrCreate([
            'permit_id' => $permit->id,
            'notice_kind' => $kind,
        ])->wasRecentlyCreated;
    }
}
