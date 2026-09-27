<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Models\Permit;
use App\Support\PermitFace;
use Illuminate\Http\JsonResponse;

/**
 * PUBLIC permit verification (no auth) — what a permit's QR code opens.
 *
 * ── What it answers, and from where ──────────────────────────────────────────
 *
 * The question a person scanning the certificate on a shop wall is asking is
 * "is this paper real, and is it still good?" So the payload is the handful of
 * fields that let them compare the paper in front of them with the register
 * (checklist item 22): permit number, business and trade name, address, permit
 * type, valid-until and the status — Revoked and Suspended included.
 *
 * The business details are read from the certificate's FACE — the snapshot
 * taken when it was signed (`PermitFace::forPrinting`) — not from the business
 * as it reads today. The scanner is holding the printed certificate; showing
 * them a newer address than the one printed on it would make a genuine permit
 * look forged.
 *
 * ── What it deliberately leaves out ──────────────────────────────────────────
 *
 * The owner's personal name. Permit numbers are sequential and guessable, so
 * this endpoint is effectively a public list of every business the City has
 * licensed; adding owners would make it a public list of people. The trade name
 * is shown instead. This is a judgement, recorded as open in the checklist
 * report — if the City wants the owner's name here, it is one key.
 *
 * The revocation REASON, likewise. The date says the permit is no longer good;
 * why is between the City and the owner (question A26, point 4).
 */
class VerifyController extends Controller
{
    public function show(string $permitNumber): JsonResponse
    {
        $permit = Permit::with([
            'permitType',
            // withTrashed: a retired business's certificate still verifies —
            // as whatever its status says — rather than answering as if the
            // permit had never existed.
            'business' => fn ($b) => $b->withTrashed(),
            'business.address.barangay',
        ])
            ->where('permit_number', $permitNumber)
            ->first();

        abort_if(! $permit, 404, 'Permit not found.');

        $face = PermitFace::forPrinting($permit);

        /*
         * Valid means BOTH the status and the date say so. The status alone is
         * wrong on this register — `biztrack:scan-permits` flips a lapsed
         * permit to Expired, and a day's gap is enough for a certificate to
         * read Active past its term.
         */
        $inTerm = $permit->valid_until !== null && $permit->valid_until->endOfDay()->isFuture();
        $isValid = $permit->status === PermitStatus::Active && $inTerm;

        /*
         * The state to SHOW, which is the status except for one case: an
         * Active permit past its term is expired in every sense a scanner
         * cares about, and printing "Active" beside a date that has passed
         * would read as a contradiction.
         */
        $state = ($permit->status === PermitStatus::Active && ! $inTerm)
            ? PermitStatus::Expired
            : $permit->status;

        return response()->json([
            'data' => [
                'permit_number' => $permit->permit_number,
                'status' => $state?->value,
                'status_label' => $state?->label(),
                'valid_from' => optional($permit->valid_from)->toDateString(),
                'valid_until' => optional($permit->valid_until)->toDateString(),
                // Only a date, never the reason — see the class note.
                'revoked_at' => optional($permit->revoked_at)->toDateString(),
                'permit_type' => $permit->permitType ? ['name' => $permit->permitType->name] : null,
                'business' => [
                    'name' => $face['business_name'] ?? $permit->business?->name,
                    'trade_name' => $face['trade_name'] ?? null,
                    'address' => [
                        'line' => $face['address'] ?? null,
                        'barangay' => ($face['barangay'] ?? null) !== null
                            ? ['name' => $face['barangay']]
                            : null,
                        'city' => $face['city'] ?? null,
                    ],
                ],
                'is_valid' => $isValid,
            ],
        ]);
    }
}
