<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Blacklisting a business owner, and reinstating them.
 *
 * ── The owner is the thing that is blacklisted ─────────────────────────────
 *
 * [Client, 5 October 2026: "pag once blacklisted, automatic na suspended ang
 * kanyang mga businesses, di dapat basta basta ma eedit status ng mga
 * business na yon".] A blacklisting is a finding against the person. Their
 * businesses are SUSPENDED by it — not each relabelled "Blacklisted", which
 * made three rows read as three sanctions — and they stay suspended, with
 * their status locked, until the owner is reinstated.
 *
 * Their certificates follow (WorkflowService::suspendPermitsForBusiness), so
 * the QR check at a counter says suspended too.
 *
 * ── The way back undoes only what the blacklisting did ─────────────────────
 *
 * Each business the blacklisting suspended is marked so in its audit row
 * (`by_owner_blacklist`). Reinstating returns exactly those to Active, plus
 * any still labelled Blacklisted from before this rule. A business that was
 * already suspended for its own reasons stays suspended — reinstating a person
 * is not a review of a premises.
 */
class OwnerSanction
{
    public function __construct(
        private WorkflowService $workflow,
        private NotificationService $notify,
    ) {}

    /** @return Collection<int, Business> the businesses the blacklisting suspended */
    public function blacklist(User $owner, string $reason, ?User $by): Collection
    {
        $suspended = DB::transaction(function () use ($owner, $reason, $by) {
            $owner->forceFill([
                'blacklisted_at' => now(),
                'blacklist_reason' => $reason,
                'blacklisted_by' => $by?->id,
            ])->save();

            Audit::log('owner.blacklisted', $owner, ['reason' => $reason, 'by' => $by?->id]);

            $moved = $owner->businesses()
                // Already suspended, or labelled Blacklisted under the old rule: left as they are.
                ->whereNotIn('status', ['suspended', Business::STATUS_BLACKLISTED])
                ->get();

            foreach ($moved as $business) {
                $from = $business->status;
                $snapshot = Audit::snapshot($business, ['address', 'lines']);
                $business->update(['status' => 'suspended', 'status_changed_at' => now()]);

                Audit::log('business.status_changed', $business, [
                    'from' => $from,
                    'to' => 'suspended',
                    'reason' => "Owner blacklisted: {$reason}",
                    // What reinstating reads to know this suspension was the blacklisting's.
                    'by_owner_blacklist' => $owner->id,
                ], $snapshot);

                $this->workflow->suspendPermitsForBusiness($business, "Owner blacklisted: {$reason}");
            }

            // Already-suspended businesses keep their own reason, but their certificates are barred too.
            foreach ($owner->businesses()->whereIn('status', ['suspended', Business::STATUS_BLACKLISTED])->whereNotIn('id', $moved->pluck('id'))->get() as $business) {
                $this->workflow->suspendPermitsForBusiness($business, "Owner blacklisted: {$reason}");
            }

            return $moved;
        });

        // Worded as the old per-business notice was, which owners already know.
        $count = $owner->businesses()->count();
        $first = $owner->businesses()->value('name');
        $this->notify->push(
            $owner,
            'account_status',
            'Your account has been blacklisted',
            'This account has been blacklisted'
                .($count > 1
                    ? ", so all {$count} of its businesses are suspended and none of them can file or renew."
                    : ", so {$first} is suspended and cannot file or renew.")
                ." Their permits are not valid while this stands. Reason: {$reason} To ask what is needed "
                .'to have this lifted, message the City BPLO through BizTrack.',
            '/dashboard',
        );

        return $suspended;
    }

    /** @return Collection<int, Business> the businesses brought back to Active */
    public function reinstate(User $owner, string $reason, ?User $by): Collection
    {
        $restored = DB::transaction(function () use ($owner, $reason, $by) {
            $owner->forceFill([
                'blacklisted_at' => null,
                'blacklist_reason' => null,
                'blacklisted_by' => null,
            ])->save();

            Audit::log('owner.blacklist_lifted', $owner, ['reason' => $reason, 'to' => 'active', 'by' => $by?->id]);

            $back = $owner->businesses()->get()->filter(function (Business $b) use ($owner) {
                if ($b->status === Business::STATUS_BLACKLISTED) {
                    return true; // labelled under the old rule
                }
                if ($b->status !== 'suspended') {
                    return false;
                }

                $last = AuditLog::where('auditable_type', Business::class)
                    ->where('auditable_id', $b->id)
                    ->where('action', 'business.status_changed')
                    ->latest('id')
                    ->first();

                return (int) ($last?->changes['by_owner_blacklist'] ?? 0) === $owner->id;
            });

            foreach ($back as $business) {
                $from = $business->status;
                $business->update(['status' => 'active', 'status_changed_at' => now()]);

                Audit::log('business.status_changed', $business, [
                    'from' => $from,
                    'to' => 'active',
                    'reason' => "Owner reinstated: {$reason}",
                    'released_with_owner' => $owner->id,
                ]);

                $this->workflow->restorePermitsForBusiness($business);
            }

            return $back->values();
        });

        $this->notify->push(
            $owner,
            'account_status',
            'Your account has been restored',
            "Your account is no longer blacklisted. Reason: {$reason} "
                .($restored->count() === 1
                    ? "{$restored->first()->name} is active again, and its permits are restored."
                    : "{$restored->count()} of your businesses are active again, and their permits are restored."),
            '/dashboard',
        );

        return $restored;
    }
}
