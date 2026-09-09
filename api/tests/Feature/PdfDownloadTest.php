<?php

use App\Models\Barangay;
use App\Models\FeeAssessment;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/*
 * Item 48: the receipt downloaded as a near-blank page. dompdf's CPDF backend
 * was rendered twice (once to archive, once to download), which re-deflated the
 * font streams. Item 58: the ordinance parenthetical is noise on a receipt.
 */

/**
 * How many /FlateDecode streams will not inflate. A second dompdf render
 * deflates the already-deflated font programs, which is exactly what poppler
 * reports as "Unknown compression method in flate stream". A sound file has 0.
 */
function brokenFlateStreams(string $pdf): int
{
    $broken = 0;
    preg_match_all('/>>\s*stream\r?\n/', $pdf, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$match, $at]) {
        $dictAt = max(0, $at - 800);
        if (! str_contains(substr($pdf, $dictAt, $at - $dictAt), '/FlateDecode')) {
            continue;
        }
        $bodyAt = $at + strlen($match);
        $end = strpos($pdf, 'endstream', $bodyAt);
        if ($end !== false && @gzuncompress(rtrim(substr($pdf, $bodyAt, $end - $bodyAt), "\r\n")) === false) {
            $broken++;
        }
    }

    return $broken;
}

function paidReceiptPayment(): Payment
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Receipt Test Store',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-88201',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '2 Receipt St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return Payment::where('application_id', $appId)->firstOrFail();
}

it('downloads a structurally valid single-page receipt', function () {
    $payment = paidReceiptPayment();

    authAs('owner@biztrack.local');
    $res = $this->get("/api/v1/payments/{$payment->id}/receipt")->assertOk();
    $bytes = $res->getContent();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and(str_contains(substr($bytes, -1024), '%%EOF'))->toBeTrue();

    // Every embedded font stream must inflate: 0 pages and stray marks in the
    // reader was the symptom of the double render.
    expect(brokenFlateStreams($bytes))->toBe(0);

    $pages = (new Parser)->parseContent($bytes)->getPages();
    expect($pages)->toHaveCount(1);
    expect($pages[0]->getText())->toContain('OFFICIAL RECEIPT');

    // The archived copy is the same bytes, not a second (corrupting) render.
    expect(Storage::disk('local')->get("private/receipts/{$payment->id}.pdf"))->toBe($bytes);
});

it('downloads a structurally valid permit certificate', function () {
    $permit = Permit::whereHas('business', fn ($q) => $q->whereHas(
        'owner', fn ($o) => $o->where('email', 'owner@biztrack.local')
    ))->firstOrFail();

    authAs('owner@biztrack.local');
    $bytes = $this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent();

    expect(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and(brokenFlateStreams($bytes))->toBe(0);

    $pages = (new Parser)->parseContent($bytes)->getPages();
    expect($pages)->toHaveCount(1);
    expect($pages[0]->getText())->toContain('CITY OF MALABON');

    expect(Storage::disk('local')->get("private/permits/{$permit->id}.pdf"))->toBe($bytes);
});

it('drops the ordinance parenthetical from receipt line items', function () {
    $payment = paidReceiptPayment();

    FeeAssessment::where('application_id', $payment->application_id)->update([
        'line_items' => [
            ['label' => 'Application filing fee (business permit application)', 'amount' => 100],
            ['label' => 'Garbage fee — Schedule S(5): retailers, by aggregate area', 'amount' => 50],
        ],
    ]);

    authAs('owner@biztrack.local');
    $res = $this->get("/api/v1/payments/{$payment->id}/receipt")->assertOk();
    $text = (new Parser)->parseContent($res->getContent())->getText();

    expect($text)->toContain('Application filing fee')
        ->and($text)->not->toContain('business permit application')
        // An inline code is not a parenthetical: it stays.
        ->and($text)->toContain('Schedule S(5)');
});
