<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which office's figures an analytics reader may see, decided on the server.
 *
 * Checklist "Manage Approved Permits – Ken", item 1: one analytics dashboard
 * for every office. Each office admin (CHO, BFP, CPDO, OBO, CENRO, BPLO) opens
 * it scoped to their own office; BPLO and the super admin may switch to any
 * office or to all of them.
 *
 * ── Why this is a boundary and not a filter ─────────────────────────────────
 *
 * The office arrives as a query parameter because BPLO and the super admin
 * choose it from a menu. For everybody else the parameter is not a choice at
 * all: an office account is answered with its own office whatever it sends,
 * and a request naming another office — or "all" — is refused with 403 rather
 * than quietly narrowed. Refusing is the louder of the two options on purpose:
 * a narrowed answer to a request for another office's figures would look, to
 * whoever crafted it, like that office's figures.
 *
 * The line between the two kinds of reader is the one the register already
 * draws: `application.view_any_office` (ApplicationVisibility::readsEveryOffice).
 * The readers who may open any office's filing one at a time are the readers
 * who may see any office's totals. Nothing new is granted by the aggregate.
 *
 * ── What "an office's figures" means ────────────────────────────────────────
 *
 * The same boundary the rest of the product uses, so the dashboard cannot count
 * a filing the office's own queue would not show:
 *
 *  - applications: those ROUTED to the office (an `application_assignments` row
 *    for its department) — ApplicationVisibility::scope's rule;
 *  - permits: those of a permit type the office ISSUES
 *    (`permit_types.issuing_department_id`) — readsPermitOf's rule;
 *  - inspections, reviews, threads and requests: those carrying the office's
 *    own `department_id`;
 *  - businesses: those with at least one filing routed to the office.
 *
 * A reader with no department and no cross-office permission (a misconfigured
 * office account) is refused, not shown the city: the boundary fails closed,
 * as ApplicationVisibility::scope does.
 */
final class AnalyticsOffice
{
    /** The query value that asks for every office at once. */
    public const ALL = 'all';

    /**
     * Every office a dashboard can be scoped to: the departments that issue a
     * permit type, in the order the register lists the permit types.
     *
     * Read from the register rather than listed here, so an office that starts
     * issuing a clearance appears in the menu without a code change.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function all(): array
    {
        $rows = DB::table('departments')
            ->join('permit_types', 'permit_types.issuing_department_id', '=', 'departments.id')
            ->orderBy('permit_types.id')
            ->get(['departments.code', 'departments.name']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->code] ??= ['code' => (string) $row->code, 'name' => (string) $row->name];
        }

        return array_values($out);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    /** May this reader choose the office, including "all"? */
    public static function canSwitch(User $user): bool
    {
        return ApplicationVisibility::readsEveryOffice($user);
    }

    /**
     * The office this request is answered for: a department code, or null for
     * every office.
     *
     * - A reader who may switch gets what they asked for. Asking for nothing
     *   gives their own office when they have one on this list (BPLO opens on
     *   BPLO, per the checklist wording) and every office otherwise (the super
     *   admin has no department). An unknown code is a 422.
     * - Any other reader gets their own office. Asking for another office, or
     *   for "all", is a 403.
     */
    public static function forRequest(User $user, mixed $requested): ?string
    {
        $requested = is_string($requested) ? strtoupper(trim($requested)) : '';
        $own = self::ownCode($user);

        if (self::canSwitch($user)) {
            if ($requested === '') {
                return $own;
            }
            if ($requested === strtoupper(self::ALL)) {
                return null;
            }
            abort_unless(in_array($requested, self::codes(), true), 422, 'That office is not one the dashboard can show.');

            return $requested;
        }

        abort_if($own === null, 403, 'Your account is not assigned to an office, so there are no office figures to show.');
        abort_unless(
            $requested === '' || $requested === $own,
            403,
            'You can only see your own office’s figures.',
        );

        return $own;
    }

    /**
     * What the screen needs to draw its office control: the office in force,
     * whether the reader may change it, and the choices when they may.
     *
     * @return array{office: string|null, office_name: string, can_switch: bool, offices: list<array{code: string, name: string}>}
     */
    public static function describe(User $user, ?string $office): array
    {
        $all = self::all();
        $name = 'All offices';
        foreach ($all as $row) {
            if ($row['code'] === $office) {
                $name = $row['name'];
            }
        }

        $canSwitch = self::canSwitch($user);

        return [
            'office' => $office,
            'office_name' => $name,
            'can_switch' => $canSwitch,
            // An office account is not sent the list: it has nothing to choose.
            'offices' => $canSwitch ? $all : [],
        ];
    }

    /** The reader's own office code, when it is one a dashboard can show. */
    public static function ownCode(User $user): ?string
    {
        if (! $user->department_id) {
            return null;
        }

        $code = DB::table('departments')->where('id', $user->department_id)->value('code');

        return $code !== null && in_array((string) $code, self::codes(), true) ? (string) $code : null;
    }

    /**
     * The office's identifiers as the fact queries need them.
     *
     * @return array{code: string, department_id: int, permit_type_ids: list<int>}|null
     */
    public static function scope(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }

        $departmentId = DB::table('departments')->where('code', $code)->value('id');
        if ($departmentId === null) {
            throw new \InvalidArgumentException("Unknown office [{$code}].");
        }

        return [
            'code' => $code,
            'department_id' => (int) $departmentId,
            'permit_type_ids' => DB::table('permit_types')
                ->where('issuing_department_id', $departmentId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        ];
    }
}
