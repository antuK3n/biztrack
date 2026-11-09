<?php

use App\Enums\PermitStatus;
use App\Models\AuditLog;
use App\Models\Permit;
use App\Models\PermitType;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/*
 * A certificate out of force says so across its face [client, 10 November
 * 2026: "Whenever the business permit is expired, is it really best to just
 * put an 'Expired' word in the permit itself? Same with other statuses." —
 * the client chose a watermark with a dated banner].
 *
 * Each case below renders the real PDF through dompdf and reads its text back,
 * because a watermark that the stylesheet declares but dompdf drops would pass
 * any test of the view's HTML. The word appears twice when it works: once in
 * the watermark, once at the head of the banner.
 *
 * And the copy the City issued stays as issued: a download after the permit
 * left force is drawn fresh, but no longer overwrites the archived file.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** A certificate of `$code` on the seeded owner's business, with `$attrs` over it. */
function markedPermit(string $code, array $attrs = []): Permit
{
    $seed = Permit::whereHas('business', fn ($q) => $q->whereHas(
        'owner', fn ($o) => $o->where('email', 'owner@biztrack.local')
    ))->firstOrFail();

    return Permit::create([
        'permit_number' => "MARK-{$code}-".random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now()->subYear(),
        ...$attrs,
    ]);
}

/**
 * The PDF as the owner downloads it, and its text with every run of
 * whitespace made one space: a line the sheet wraps reads as one line.
 */
function markedPdf(Permit $permit): array
{
    authAs('owner@biztrack.local');
    $bytes = test()->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent();
    $pdf = (new Parser)->parseContent($bytes);

    return [$bytes, preg_replace('/\s+/u', ' ', $pdf->getText()), count($pdf->getPages())];
}

const WATERMARK_QR_NOTE = 'Validity is confirmed only by scanning this QR.';

it('watermarks an expired certificate and dates the day its term ended, on every sheet', function (string $code) {
    $ended = now()->subMonths(2)->startOfDay();
    $permit = markedPermit($code, ['status' => PermitStatus::Expired, 'valid_until' => $ended->toDateString()]);

    [, $text, $pages] = markedPdf($permit);

    expect(substr_count($text, 'EXPIRED'))->toBeGreaterThanOrEqual(2)
        ->and($text)->toContain('EXPIRED ON '.strtoupper($ended->format('F j, Y')))
        ->and($text)->toContain(WATERMARK_QR_NOTE)
        // The banner and the watermark fit the sheet; neither pushes it onto a second page.
        ->and($pages)->toBe(1);
})->with(['BUSINESS', 'CEC', 'FSIC', 'ZONING', 'SANITARY', 'OCCUPANCY']);

it('prints an Active permit past its term as expired, before the nightly scan does', function () {
    $ended = now()->subDay()->startOfDay();
    $permit = markedPermit('BUSINESS', ['valid_until' => $ended->toDateString()]);

    [, $text] = markedPdf($permit);

    expect($permit->fresh()->status)->toBe(PermitStatus::Active)
        ->and(substr_count($text, 'EXPIRED'))->toBeGreaterThanOrEqual(2)
        ->and($text)->toContain('EXPIRED ON '.strtoupper($ended->format('F j, Y')));
});

it('does not mark an Occupancy Permit past its date, which is an inspection and not an expiry', function () {
    $permit = markedPermit('OCCUPANCY', ['valid_until' => now()->subDay()->toDateString()]);

    [, $text] = markedPdf($permit);

    expect($text)->not->toContain('EXPIRED');
});

it('prints a renewed permit as replaced, with the day and the permit that replaced it', function () {
    $old = markedPermit('BUSINESS', ['status' => PermitStatus::Superseded]);
    $issued = now()->subDays(5);
    $new = markedPermit('BUSINESS', ['prior_permit_id' => $old->id, 'issued_at' => $issued]);

    [, $text] = markedPdf($old);

    expect(substr_count($text, 'REPLACED'))->toBeGreaterThanOrEqual(2)
        ->and($text)->toContain('REPLACED ON '.strtoupper($issued->format('F j, Y'))." BY {$new->permit_number}")
        ->and($text)->not->toContain('SUPERSEDED');
});

it('prints a revoked permit with its date and never its reason', function () {
    $on = now()->subDays(3);
    $permit = markedPermit('BUSINESS', [
        'status' => PermitStatus::Revoked,
        'revoked_at' => $on,
        'revoked_reason' => 'Operating a karaoke bar without a liquor licence',
    ]);

    [, $text] = markedPdf($permit);

    expect(substr_count($text, 'REVOKED'))->toBeGreaterThanOrEqual(2)
        ->and($text)->toContain('REVOKED ON '.strtoupper($on->format('F j, Y')))
        ->and($text)->not->toContain('karaoke');
});

it('prints a suspended permit with the day it was suspended', function () {
    $on = now()->subDays(4);
    $permit = markedPermit('SANITARY', ['status' => PermitStatus::Suspended, 'suspended_at' => $on]);

    [, $text] = markedPdf($permit);

    expect(substr_count($text, 'SUSPENDED'))->toBeGreaterThanOrEqual(2)
        ->and($text)->toContain('SUSPENDED SINCE '.strtoupper($on->format('F j, Y')));
});

it('dates a retired permit from its status history', function () {
    $permit = markedPermit('BUSINESS', ['status' => PermitStatus::Retired]);
    $log = AuditLog::create([
        'action' => 'permit.status_changed',
        'auditable_type' => Permit::class,
        'auditable_id' => $permit->id,
        'changes' => ['from' => 'active', 'to' => 'retired'],
    ]);
    $log->forceFill(['created_at' => now()->subDays(6)])->save();

    [, $text] = markedPdf($permit);

    expect(substr_count($text, 'RETIRED'))->toBeGreaterThanOrEqual(2)
        ->and($text)->toContain('RETIRED ON '.strtoupper(now()->subDays(6)->format('F j, Y')));
});

it('prints no watermark or banner on a permit in force, and still points to the QR', function () {
    $permit = markedPermit('BUSINESS');

    [, $text] = markedPdf($permit);

    foreach (['EXPIRED', 'SUSPENDED', 'REVOKED', 'REPLACED', 'RETIRED', 'REJECTED'] as $word) {
        expect($text)->not->toContain($word);
    }
    expect($text)->toContain(WATERMARK_QR_NOTE);
});

it('keeps the copy issued while in force when the permit is downloaded after it expires', function () {
    $permit = markedPermit('BUSINESS', ['valid_until' => now()->addDays(2)->toDateString()]);
    $path = "private/permits/{$permit->id}.pdf";

    [$issued] = markedPdf($permit);
    expect(Storage::disk('local')->get($path))->toBe($issued)
        ->and($permit->fresh()->pdf_path)->toBe($path);

    $this->travel(5)->days();
    [$later, $text] = markedPdf($permit);

    expect($text)->toContain('EXPIRED ON')
        ->and(Storage::disk('local')->get($path))->toBe($issued)
        ->and($later)->not->toBe($issued);
});

it('archives nothing for a permit first downloaded out of force', function () {
    $permit = markedPermit('BUSINESS', ['status' => PermitStatus::Revoked, 'revoked_at' => now()]);

    markedPdf($permit);

    expect(Storage::disk('local')->exists("private/permits/{$permit->id}.pdf"))->toBeFalse()
        ->and($permit->fresh()->pdf_path)->toBeNull();
});
