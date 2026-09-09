<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mark as superseded any permit a renewal has already replaced.
 *
 * `PermitStatus::Superseded` was added on 9 September 2026, after a renewal was
 * found to leave a business holding two live certificates of the same type: the
 * old permit stayed `active` until its own date passed, so for the length of
 * the overlap it appeared on the applicant's profile and in the renewal picker
 * beside the certificate that had replaced it. `WorkflowService::issuePermitFor`
 * now supersedes the predecessor as it issues the successor. This carries the
 * same correction to rows written before it.
 *
 * ── Deliberately conservative ─────────────────────────────────────────────
 *
 * Four conditions, all required, and the type check is the one that matters:
 * until this same release `Permit::booted` stamped the renewal's PRIMARY prior
 * permit onto every certificate the filing issued, without checking the type —
 * so a two-permit renewal wrote a link from a new zoning permit to an old
 * sanitary one. Superseding on the strength of those links would retire a live
 * sanitary permit because a zoning permit was renewed. Same business, same
 * permit type, successor still live, predecessor still active — anything else
 * is left exactly as it is.
 *
 * No `down()` that reverses the status. It cannot be written honestly: a permit
 * reading `superseded` here may have been set by this migration or by an
 * ordinary renewal since, and flipping the lot back to `active` would revive
 * certificates the register has correctly retired. The column is a string with
 * no enum constraint, so rolling the code back leaves these rows readable and
 * inert rather than broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = DB::table('permits')->where('status', 'active')->count();

        $ids = DB::table('permits as p')
            ->where('p.status', 'active')
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('permits as s')
                ->whereColumn('s.prior_permit_id', 'p.id')
                ->whereColumn('s.permit_type_id', 'p.permit_type_id')
                ->whereColumn('s.business_id', 'p.business_id')
                ->where('s.status', 'active'))
            ->pluck('p.id');

        if ($ids->isNotEmpty()) {
            DB::table('permits')->whereIn('id', $ids)->update(['status' => 'superseded']);
        }

        $after = DB::table('permits')->where('status', 'active')->count();

        echo sprintf(
            "  permits active before: %d; superseded by this migration: %d; active after: %d\n",
            $before,
            $ids->count(),
            $after,
        );
    }

    public function down(): void
    {
        // Intentionally empty — see the note above.
    }
};
