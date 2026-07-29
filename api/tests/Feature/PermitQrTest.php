<?php

use App\Models\Permit;
use App\Support\QrCode;
use Illuminate\Support\Facades\Storage;

/*
 * Item 73: the certificate printed the verification code and URL but no QR.
 * QrCode::svgDataUri() asked chillerlan/php-qrcode for the output backend by a
 * constant (QROutputInterface::MARKUP_SVG) that v6 no longer defines, the
 * resulting Error was swallowed by the catch, and the blade's @if($qr) then
 * rendered nothing at all.
 */

/**
 * Vector line operators in the page content streams.
 *
 * dompdf draws an embedded SVG as paths, so the QR's modules show up here in
 * the thousands. The rest of the certificate is text plus one horizontal rule
 * and contributes none, which makes this a clean present/absent signal:
 * measured 0 without the QR against 3362 with it.
 */
function pdfLineOps(string $pdf): int
{
    $ops = 0;
    preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $matches);

    foreach ($matches[1] as $stream) {
        $body = @gzuncompress(trim($stream, "\r\n"));
        if ($body === false || $body === '') {
            continue;
        }
        // Page content is operator text; embedded font programs are binary.
        $printable = strlen(preg_replace('/[^\x20-\x7e\t\r\n]/', '', $body));
        if ($printable / strlen($body) < 0.99) {
            continue;
        }
        $ops += preg_match_all('/^\s*[\d.]+ [\d.]+ l$/m', $body);
    }

    return $ops;
}

it('renders a QR code as an SVG data URI', function () {
    $uri = QrCode::svgDataUri('https://biztrack.test/verify/MCB-2026-000001');

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
    expect($svg)->toBeString()
        ->and($svg)->toContain('<svg')
        ->and($svg)->toContain('<path');

    // A real module matrix, not an empty canvas: the smallest QR is 21 modules
    // plus the 4-module quiet zone on each side, and it is always square.
    expect($svg)->toMatch('/viewBox="0 0 (\d+) \1"/');
    preg_match('/viewBox="0 0 (\d+) /', $svg, $box);
    expect((int) $box[1])->toBeGreaterThanOrEqual(29);
});

it('draws the verification QR on the permit certificate', function () {
    $permit = Permit::whereHas('business', fn ($q) => $q->whereHas(
        'owner', fn ($o) => $o->where('email', 'owner@biztrack.local')
    ))->firstOrFail();

    authAs('owner@biztrack.local');
    $bytes = $this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent();

    expect(pdfLineOps($bytes))->toBeGreaterThan(500);

    // The archived copy carries the QR too — same bytes, one render (item 48).
    expect(Storage::disk('local')->get("private/permits/{$permit->id}.pdf"))->toBe($bytes);
});
