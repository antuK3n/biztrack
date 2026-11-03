<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of proof that a bill was paid at the City Hall counter.
 *
 * Two people make these (thesis panel revisions, matrix items 4 and 6):
 *
 *   the owner    from the Pay page, after paying at the counter — the OR
 *                number and the date it was paid, a photo of the receipt, or
 *                both. It is PENDING until BPLO records the payment, which
 *                fills `payment_id`. Sending proof never marks the bill paid.
 *   the officer  a photo attached at Mark as paid, born linked to the payment.
 *
 * Rows are never deleted. An owner who sends again before BPLO confirms
 * replaces the pending proof: the old row is stamped `superseded_at` and kept,
 * with its file and its audit row.
 *
 * BPLO can also reject the pending proof, with a reason (Ken, 3 November
 * 2026): the row is stamped `rejected_at`, `rejected_by_user_id` and
 * `rejection_reason` and kept the same way. A rejected proof is never pending
 * again, so Mark as paid — which needs a pending proof — waits for the owner
 * to send a new one.
 */
class PaymentProof extends Model
{
    protected $fillable = [
        'application_id', 'fee_assessment_id', 'payment_id', 'submitted_by_user_id',
        'or_number', 'or_date', 'file_path', 'original_filename', 'mime_type', 'size_bytes',
        'submitted_at', 'superseded_at', 'rejected_at', 'rejected_by_user_id', 'rejection_reason',
    ];

    protected $casts = [
        'or_date' => 'date',
        'size_bytes' => 'integer',
        'submitted_at' => 'datetime',
        'superseded_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * The proof the owner sent that BPLO has not acted on yet, or null.
     *
     * Not merely the newest unlinked row. An owner can send proof and then pay
     * online after all; that proof is never linked to anything, and without
     * the cut-off in `unsettled()` it would come back as "pending" on any
     * later bill the filing carries — a stale OR number pre-filled in front of
     * the officer. So only proof sent after the filing's last completed
     * payment counts. Nor a rejected one: BPLO has acted on it.
     */
    public static function pendingFor(Application $application): ?self
    {
        return self::pendingForEach([$application->id])[$application->id] ?? null;
    }

    /**
     * `pendingFor()` for a page of filings at once, keyed by application id;
     * a filing with nothing pending is absent. Two queries whatever the page
     * size, for the Manage Applications list (ApplicationController::index),
     * whose Pending Payment cards each say whether the receipt is in.
     *
     * @param  list<int>  $applicationIds
     * @return array<int, self>
     */
    public static function pendingForEach(array $applicationIds): array
    {
        return self::unsettled($applicationIds, withRejected: false);
    }

    /**
     * The owner's newest proof on this bill whatever BPLO made of it —
     * waiting, or rejected — or null. The owner's Pay page reads it, so that a
     * rejected receipt is shown with its reason instead of vanishing.
     *
     * Superseded rows are never it: a replacement is always newer. Nor one
     * linked to a payment: that bill is settled, and the cut-off below puts
     * it behind the payment it settled.
     */
    public static function latestFor(Application $application): ?self
    {
        return self::unsettled([$application->id], withRejected: true)[$application->id] ?? null;
    }

    /**
     * The newest unlinked, unsuperseded proof per filing, sent after that
     * filing's last completed payment — and, unless asked, not rejected.
     *
     * Filtered here rather than in SQL because the cut-off is per filing. The
     * rows come newest first, so the first one past a filing's cut-off is its
     * answer and every later one is older; that is the order `latest()` gave
     * the single-filing query this replaced.
     *
     * @param  list<int>  $applicationIds
     * @return array<int, self>
     */
    private static function unsettled(array $applicationIds, bool $withRejected): array
    {
        if ($applicationIds === []) {
            return [];
        }

        $lastPaid = Payment::query()
            ->whereIn('application_id', $applicationIds)
            ->where('status', PaymentStatus::Completed->value)
            ->groupBy('application_id')
            ->selectRaw('application_id, max(paid_at) as last_paid')
            ->pluck('last_paid', 'application_id');

        $proofs = self::query()
            ->whereIn('application_id', $applicationIds)
            ->whereNull('payment_id')
            ->whereNull('superseded_at')
            ->when(! $withRejected, fn ($q) => $q->whereNull('rejected_at'))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $found = [];
        foreach ($proofs as $proof) {
            $id = $proof->application_id;
            if (isset($found[$id])) {
                continue;
            }
            $cutOff = $lastPaid[$id] ?? null;
            if ($cutOff !== null && ! $proof->submitted_at->greaterThan(Carbon::parse($cutOff))) {
                continue;
            }
            $found[$id] = $proof;
        }

        return $found;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function hasPhoto(): bool
    {
        return $this->file_path !== null;
    }
}
