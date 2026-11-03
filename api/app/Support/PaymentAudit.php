<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\PaymentProof;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;

/**
 * The money rows of the audit trail, and the receipts behind them.
 *
 * Audit Logs' "Payments" filter asks for `group=payments`, which is ACTIONS
 * below (AuditLogController). Each row the screen is given also carries the
 * receipt photos it is about, which Details shows (`receipts()`).
 */
final class PaymentAudit
{
    /**
     * Every action written about a payment, a receipt or the payment switches.
     *
     * A list of exact keys rather than `LIKE 'payment%'`: underscore is LIKE's
     * own single-character wildcard and the way to escape it differs between
     * SQLite, MySQL and Postgres, and a prefix would also take in whatever a
     * later branch happens to name `payment…`. AuditLogPaymentsTest reads every
     * `Audit::log('payment…'` in app/ and fails if one is missing here, so a
     * new action cannot quietly drop out of the filter.
     *
     * Left out on purpose:
     *   file.downloaded           a receipt or invoice being opened; that is
     *                             "Downloaded a file", and opening a receipt
     *                             from this very filter writes one
     *   permit_fee.deferred,      a fee billed later or added to a bill; what
     *   permit_fee.folded_…       is owed, not money paid
     *
     * `debug.payments` is in: the Debug page flips the same switches as
     * `payment_gateway.*` but writes only its own row (Debug\PaymentsController),
     * so without it a switch made there would be missing from this view.
     *
     * @var list<string>
     */
    public const ACTIONS = [
        // A payment's own life, online or at the counter.
        'payment.opened',
        'payment.completed',
        'payment.failed',
        'payment.unconfirmed',
        'payment.abandoned',
        'payment.held',
        'payment.after_filing_closed',
        'payment.double_paid',
        'payment.flagged',
        // KwikPay callbacks that settled nothing.
        'payment.callback_ignored',
        'payment.callback_refused',
        'payment.callback_unmatched',
        // The owner's receipt, and BPLO's rejection of it.
        'payment_proof.submitted',
        'payment_proof.rejected',
        // How owners pay, what KwikPay collects, what marks a payment paid.
        'payment_gateway.switched',
        'payment_gateway.charge_switched',
        'payment_gateway.confirm_switched',
        'debug.payments',
    ];

    /**
     * The receipt files behind a page of audit rows, keyed by the row's id.
     * A row with none is absent.
     *
     *   payment.completed        the proofs named in its `proof_ids`, in
     *                            that order: the owner's, then the officer's
     *   payment_proof.submitted  the proof itself, the row's subject
     *   payment_proof.rejected   likewise
     *
     * Only a proof whose file is on the disk: one sent as an OR number alone
     * has none, and one whose file has gone would only answer the screen's
     * fetch with a 404. The stored path never leaves the server; the screen
     * fetches the picture by id from `payment-proofs/{id}/file`, which
     * applies its own readers and writes its own `file.downloaded` row.
     *
     * One query for the whole page, whatever its size.
     *
     * @param  iterable<AuditLog>  $logs
     * @return array<int, list<array{id: int, mime_type: ?string, file_name: ?string}>>
     */
    public static function receipts(iterable $logs): array
    {
        $wanted = [];
        foreach ($logs as $log) {
            $ids = self::proofIds($log);
            if ($ids !== []) {
                $wanted[$log->id] = $ids;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $disk = Storage::disk('local');
        $files = PaymentProof::query()
            ->whereIn('id', array_unique(array_merge(...array_values($wanted))))
            ->whereNotNull('file_path')
            ->get(['id', 'file_path', 'original_filename', 'mime_type'])
            ->filter(fn (PaymentProof $proof) => $disk->exists($proof->file_path))
            ->keyBy('id');

        $receipts = [];
        foreach ($wanted as $logId => $ids) {
            $found = [];
            foreach ($ids as $id) {
                $proof = $files->get($id);
                if ($proof !== null) {
                    $found[] = [
                        'id' => $proof->id,
                        'mime_type' => $proof->mime_type ?? self::guessMime($proof->file_path),
                        'file_name' => $proof->original_filename,
                    ];
                }
            }
            if ($found !== []) {
                $receipts[$logId] = $found;
            }
        }

        return $receipts;
    }

    /** @return list<int> */
    private static function proofIds(AuditLog $log): array
    {
        if ($log->action === 'payment.completed') {
            $ids = $log->changes['proof_ids'] ?? [];

            return is_array($ids) ? array_values(array_filter($ids, 'is_int')) : [];
        }

        if (in_array($log->action, ['payment_proof.submitted', 'payment_proof.rejected'], true)
            && $log->auditable_type === PaymentProof::class
            && $log->auditable_id !== null) {
            return [(int) $log->auditable_id];
        }

        return [];
    }

    /** From the stored file's extension, for a row written without a type. */
    private static function guessMime(string $path): ?string
    {
        return MimeTypes::getDefault()->getMimeTypes(pathinfo($path, PATHINFO_EXTENSION))[0] ?? null;
    }
}
