<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * Rows are never overwritten or deleted. An owner who sends again before BPLO
 * confirms replaces the pending proof: the old row is stamped `superseded_at`
 * and kept, with its file and its audit row.
 */
class PaymentProof extends Model
{
    protected $fillable = [
        'application_id', 'fee_assessment_id', 'payment_id', 'submitted_by_user_id',
        'or_number', 'or_date', 'file_path', 'original_filename', 'mime_type', 'size_bytes',
        'submitted_at', 'superseded_at',
    ];

    protected $casts = [
        'or_date' => 'date',
        'size_bytes' => 'integer',
        'submitted_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    /**
     * The proof the owner sent that BPLO has not acted on yet, or null.
     *
     * Not merely the newest unlinked row. An owner can send proof and then pay
     * online after all; that proof is never linked to anything, and without
     * the cut-off below it would come back as "pending" on any later bill the
     * filing carries — a stale OR number pre-filled in front of the officer.
     * So only proof sent after the filing's last completed payment counts.
     */
    public static function pendingFor(Application $application): ?self
    {
        $lastPaid = Payment::where('application_id', $application->id)
            ->where('status', PaymentStatus::Completed->value)
            ->max('paid_at');

        return self::query()
            ->where('application_id', $application->id)
            ->whereNull('payment_id')
            ->whereNull('superseded_at')
            ->when($lastPaid !== null, fn ($q) => $q->where('submitted_at', '>', $lastPaid))
            ->latest('submitted_at')
            ->latest('id')
            ->first();
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

    public function hasPhoto(): bool
    {
        return $this->file_path !== null;
    }
}
