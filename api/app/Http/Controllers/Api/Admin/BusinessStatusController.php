<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin business-status management (permission owner.manage_status). Backs the
 * Owner Status page: list businesses + set active|flagged|suspended|blacklisted.
 */
class BusinessStatusController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    private const LABELS = [
        'active' => 'Active',
        'flagged' => 'Flagged',
        'suspended' => 'Suspended',
        'blacklisted' => 'Blacklisted',
    ];

    /**
     * The business roster. Paginated, newest registration first.
     *
     * 705 rows and 122 KB unpaged. `q` and `status` are here so the admin can
     * find the one business they came for instead of paging to it — a roster you
     * can only walk is a roster nobody uses.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'in:active,flagged,suspended,blacklisted'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        /*
         * ── The arrears come down with the row ────────────────────────────────
         *
         * Client's decision, 17 September 2026: deferred permit fees *"wait
         * indefinitely, and are visible"*. This is the visible half — the
         * business record, wherever an admin looks a business up.
         *
         * Eager-loaded rather than counted per row. This list pages 25
         * businesses and the register holds hundreds; a `sum` per row is the
         * N+1 that makes an admin screen feel broken, and the figure is wanted
         * on every row rather than on demand.
         *
         * `outstanding` in the constraint, not `unclaimed`: a fee already on an
         * unpaid January bill is money the LGU has not received, and an arrears
         * column that hid it would show zero for every business mid-renewal.
         */
        $query = Business::with([
            'owner:id,name',
            'unbilledPermitFees' => fn ($q) => $q->outstanding()->with('permitType:id,code,name')->orderBy('incurred_at'),
        ]);

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%{$q}%")));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $businesses = collect($page->items())
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'owner' => $b->owner ? ['id' => $b->owner->id, 'name' => $b->owner->name] : null,
                'status' => $b->status,
                'status_label' => self::LABELS[$b->status] ?? ucfirst((string) $b->status),
                'created_at' => optional($b->created_at)->toISOString(),
                /*
                 * Deferred permit fees, as a total AND itemised.
                 *
                 * Both, because they answer different questions and the screen
                 * shows both: the total is what the LGU is owed, and the items
                 * are what it is owed FOR — "Sanitary Permit, June" is what
                 * turns a figure into something an officer can raise with the
                 * owner on the phone.
                 *
                 * An empty list and a zero are the honest answer for a business
                 * with nothing deferred, rather than omitting the keys: a
                 * missing key reads as "not loaded" to a screen that has to
                 * tell those apart.
                 */
                'unbilled_fees' => [
                    'total' => round((float) $b->unbilledPermitFees->sum('amount'), 2),
                    'items' => $b->unbilledPermitFees->map(fn ($fee) => [
                        'permit_type' => $fee->permitType?->name,
                        'permit_code' => $fee->permitType?->code,
                        'amount' => (float) $fee->amount,
                        'incurred_at' => optional($fee->incurred_at)->toISOString(),
                        /*
                         * Claimed but unpaid is a real and different state: the
                         * fee is on a bill the applicant has been shown and has
                         * not settled. An officer chasing it needs to know that
                         * a renewal is already in flight carrying it.
                         */
                        'on_a_bill' => $fee->billed_on_application_id !== null,
                    ])->values(),
                ],
            ])->values();

        return response()->json([
            'data' => $businesses,
            'meta' => $this->pageMeta($page),
        ]);
    }

    public function updateStatus(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,flagged,suspended,blacklisted'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $from = $business->status;

        /*
         * `status_changed_at` is only touched when the status actually moves.
         * It dates a blacklisting on the Business Closure Trend, so re-saving
         * the same status — which the roster lets an admin do, and which the QA
         * sweeps in the audit log did twice — must not shift that closure into
         * the current month. Re-blacklisting an already-blacklisted business is
         * not a second closure.
         *
         * The audit row is still written either way: "an admin looked at this
         * and left it alone, for this reason" is a fact worth keeping, it is
         * just not a status change.
         */
        $changes = ['status' => $data['status']];
        if ($data['status'] !== $from) {
            $changes['status_changed_at'] = now();
        }
        $business->update($changes);

        Audit::log('business.status_changed', $business, [
            'from' => $from,
            'to' => $data['status'],
            'reason' => $data['reason'],
        ]);

        /*
         * Tell the owner — but only when something actually moved.
         *
         * Same condition as `status_changed_at` above and for the same reason:
         * the roster lets an admin re-save the status a business already has,
         * and the QA sweeps in the audit log did exactly that. An audit row for
         * "looked at and left alone" is worth keeping; a notification saying
         * "your business is now Blacklisted" for the second time, weeks later,
         * is a false alarm to the one person least able to check.
         */
        if ($data['status'] !== $from) {
            $this->notifications->businessStatusChanged(
                $business,
                (string) $from,
                $data['status'],
                $data['reason'],
                self::LABELS[$data['status']] ?? $data['status'],
            );
        }

        return response()->json([
            'data' => [
                'id' => $business->id,
                'status' => $business->status,
                'status_label' => self::LABELS[$business->status] ?? ucfirst($business->status),
            ],
        ]);
    }

    /**
     * Move a business to another owner account.
     *
     * ── The other half of an ownership amendment ──────────────────────────
     *
     * MCG-BPLO-FO-003 section II is a CHANGE OF OWNERSHIP, and the client's
     * decision of 21 September 2026 was that the applicant states the new
     * owner and BPLO moves the account. Nothing in this codebase could: the
     * admin "Reassign" screen moves FILINGS BETWEEN OFFICERS, which shares a
     * word and does something else, and `owner_user_id` was written in exactly
     * one place — `BusinessController::store`, from the session. So an
     * approved ownership amendment landed nowhere.
     *
     * ── Why the approval does not do this itself ──────────────────────────
     *
     * Because it cannot know who to move it TO. The applicant types a name;
     * an account is a different thing, may not exist, and matching a person
     * to one by name is how a business ends up with the wrong Maria Reyes.
     * BPLO names the account, having read the Deed of Transfer the filing
     * carries, and that judgement is the step this endpoint exists to record.
     *
     * The permits move with it because they belong to the business, not to the
     * account — nothing about the certificates changes except who can now see
     * them. The OLD owner loses that visibility, which is the point.
     */
    public function transferOwner(Request $request, Business $business): JsonResponse
    {
        /*
         * By EMAIL, not by id. BPLO is holding a Deed of Transfer and a name,
         * and the one identifier they can actually get from the new owner is
         * the address that owner registered with. An id would need a roster of
         * every account in the city to pick from, which is a screen nobody
         * asked for and a cross-account read nobody needs.
         */
        $data = $request->validate([
            'owner_email' => ['required', 'email', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $from = $business->owner_user_id;
        $to = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['owner_email']))])->first();

        /*
         * The case that made this endpoint necessary and is also its commonest
         * dead end: the new owner has no BizTrack account. Said plainly, with
         * the next step in it, because the officer cannot create one for them
         * and would otherwise be looking at "invalid email".
         */
        if ($to === null) {
            throw ValidationException::withMessages([
                'owner_email' => [
                    'No BizTrack account uses that email address. The new owner has to register '
                    .'one before the business can be transferred to them.',
                ],
            ]);
        }

        /*
         * Refused rather than shrugged at. "Transferred" on a screen that did
         * nothing is the failure the Reassign dialog was fixed for on
         * 10 September 2026, one office over — an admin typing a reason,
         * pressing the button and being told it happened.
         */
        if ($to->id === $from) {
            throw ValidationException::withMessages([
                'owner_email' => ['This business already belongs to that account.'],
            ]);
        }

        /*
         * An inactive account cannot file, so transferring to one strands the
         * business: nobody could renew it, and the next January would pass
         * with no filing and no explanation.
         */
        if (! $to->is_active) {
            throw ValidationException::withMessages([
                'owner_email' => [
                    'That account is deactivated, so it could not file for this business. '
                    .'Reactivate it first.',
                ],
            ]);
        }

        $business->update(['owner_user_id' => $to->id]);

        Audit::log('business.owner_transferred', $business, [
            'from_user_id' => $from,
            'to_user_id' => $to->id,
            'reason' => $data['reason'],
        ]);

        /*
         * Both sides are told. The new owner because a business has appeared
         * in their account and they are now the one who must renew it; the
         * previous owner because one has left theirs, and a register that
         * moves a business silently is one nobody can audit from the outside.
         */
        foreach (array_filter([$to, $from === null ? null : User::find($from)]) as $person) {
            $this->notifications->push(
                $person,
                'business',
                $person->id === $to->id
                    ? 'A business was transferred to you'
                    : 'A business was transferred from your account',
                $person->id === $to->id
                    ? "{$business->name} is now registered to you. You are the one who files its "
                        .'renewals from here on.'
                    : "{$business->name} has been transferred to another owner by the BPLO.",
                '/businesses',
            );
        }

        return response()->json([
            'data' => [
                'id' => $business->id,
                'owner_user_id' => $business->owner_user_id,
                'owner_name' => $to->fullName(),
            ],
        ]);
    }
}
