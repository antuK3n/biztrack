<?php

namespace App\Support;

use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Permit;
use App\Models\PermitType;
use Illuminate\Support\Carbon;

/**
 * Where a BUSINESS stands on each required clearance — the register's answer,
 * not this filing's.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Client's words about Final Approval: *"the only purpose of Final Approval was
 * to check if all clearance permits are done."* On a NEW filing that check is
 * easy, because every clearance is attached to the filing and BPLO reads it off
 * the sheet. On a January RENEWAL it was impossible.
 *
 * `WorkflowService::attachRequiredPermitTypes()` attaches only the permits the
 * applicant TICKED, and the client's rule of 18 September 2026 is that a
 * clearance still in date is not ticked at all:
 *
 *   *"there are cases that an other permit was not needing renewal even though
 *   the business permit is being renewed ... an other permit can be reused for a
 *   business permit renewal as long as this other permit is still valid/not
 *   expired."*
 *
 * So a business permit renewed alone in January carries ONE pivot row, and BPLO
 * arrived at Final Approval with nothing whatever to check — the five
 * certificates it is supposed to be confirming were sitting in the register,
 * attached to last year's filings. This reads them from where they actually
 * are.
 *
 * ── Warned, not blocked ─────────────────────────────────────────────────────
 *
 * A gap here does not stop anything, per §5 of docs/renewal-2026-09-17.md. The
 * counter may have a reason to pass a filing whose FSIC lapsed last week, and
 * an LGU that cannot do that in the system does it on paper instead. What the
 * gap must not do is go unseen, which is what was happening.
 */
class ClearanceStanding
{
    /** Inside this many days of expiry, a certificate is worth remarking on. */
    public const EXPIRING_SOON_DAYS = 60;

    /**
     * One row per required clearance, in the reference list's own order.
     *
     * Keyed on the permit TYPE rather than on the filing's pivot rows, because
     * the question is "does this business hold all five", and a type with no
     * pivot row and no certificate is precisely the case that has to show up.
     * Driving it off the pivot rows would list only what was already there.
     *
     * @return list<array<string, mixed>>
     */
    public static function forApplication(Application $application): array
    {
        $businessId = $application->business_id;
        if ($businessId === null) {
            return [];
        }

        /*
         * The filing's own types, so a row can say whether the certificate is
         * being renewed right now or is one BPLO is choosing to rely on. Those
         * are different facts and a reader who cannot tell them apart will read
         * "valid to June" as a reason not to look at the filing's own copy.
         */
        $onThisFiling = $application->permitTypes->pluck('code')->all();

        $today = Carbon::today();

        return PermitType::query()
            ->whereIn('code', PermitType::REQUIRED_CLEARANCE_CODES)
            ->with('department')
            ->orderBy('id')
            ->get()
            ->map(function (PermitType $type) use ($businessId, $onThisFiling, $today) {
                $held = self::latestHeld($businessId, $type->id);

                $days = $held?->valid_until
                    ? $today->diffInDays($held->valid_until, false)
                    : null;

                return [
                    'permit_type_code' => $type->code,
                    'permit_type_name' => $type->name,
                    'department_code' => $type->department?->code,
                    'state' => self::state($held, $days),
                    'days_until_expiry' => $days,
                    'on_this_filing' => in_array($type->code, $onThisFiling, true),
                    'permit_id' => $held?->id,
                    'permit_number' => $held?->permit_number,
                    'valid_from' => $held?->valid_from?->toDateString(),
                    'valid_until' => $held?->valid_until?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The certificate of this type the business is actually holding.
     *
     * Ordered by `valid_until` and not by `issued_at`: a permit renewed early
     * continues the term (§3 of the renewal doc), so the newest certificate is
     * the one whose cover runs longest, and a business that renewed in April
     * while the old one ran to June holds two live rows. Nulls last, so a
     * certificate with no recorded expiry never displaces a dated one.
     *
     * `Superseded`, `Revoked` and `Suspended` are excluded — a superseded
     * certificate is last year's paper and a revoked one is the opposite of
     * cover. `Expired` is deliberately KEPT: an expired FSIC is exactly what
     * BPLO needs to see, and dropping it would report the gap as "never held
     * one", which is a different conversation with the applicant.
     */
    private static function latestHeld(int $businessId, int $permitTypeId): ?Permit
    {
        return Permit::query()
            ->where('business_id', $businessId)
            ->where('permit_type_id', $permitTypeId)
            ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->orderByRaw('valid_until IS NULL')
            ->orderByDesc('valid_until')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Four states, and `missing` is not the same as `expired`.
     *
     * A business that never held a Fire Safety certificate and one whose
     * certificate lapsed in March are both gaps, but they are not the same gap:
     * the first has never been inspected by that office and the second has. The
     * screen says which.
     *
     * `unknown` is the honest answer for a certificate with no expiry date on
     * record. There are such rows — early ones — and calling them valid would
     * have BPLO approve against a date nobody wrote down.
     */
    private static function state(?Permit $held, ?int $days): string
    {
        if ($held === null) {
            return 'missing';
        }
        if ($days === null) {
            return 'unknown';
        }
        if ($days < 0) {
            return 'expired';
        }

        return $days <= self::EXPIRING_SOON_DAYS ? 'expiring' : 'valid';
    }
}
