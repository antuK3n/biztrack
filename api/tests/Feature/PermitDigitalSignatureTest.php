<?php

use App\Models\Permit;
use App\Models\PermitType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/*
 * Revision matrix item 7: "Issued permits are now digitally signed, so their
 * authenticity can be verified. The signing is designed to use PNPKI
 * certificates."
 *
 * Each test makes its own certificate, with the command an operator runs, in
 * a folder of its own, and points the config at it; phpunit.xml keeps signing
 * off for every other suite. The tests that sign need pyHanko — the api/.venv
 * docs/digital-signature.md describes — and skip without it. The fallback
 * tests do not: a signer that cannot run is what they are about.
 */

beforeEach(function () {
    $this->signingDir = sys_get_temp_dir().'/biztrack-signing-'.Str::lower(Str::random(10));
    config([
        // Unset, as on a server: signing follows the certificate.
        'permit_signing.enabled' => null,
        'permit_signing.p12' => "{$this->signingDir}/permit-signing.p12",
        'permit_signing.password' => null,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->signingDir);
});

/** Can the configured Python import pyHanko? Asked once a run. */
function pyhankoInstalled(): bool
{
    static $installed = null;

    return $installed ??= Process::run([(string) config('permit_signing.python'), '-c', 'import pyhanko'])->successful();
}

/** A permit of this type on the seeded owner's filing, made as the certificate tests make theirs. */
function signablePermit(string $code): Permit
{
    $seed = Permit::whereHas('business', fn ($q) => $q->whereHas(
        'owner', fn ($o) => $o->where('email', 'owner@biztrack.local')
    ))->firstOrFail();

    return Permit::create([
        'permit_number' => "SIG-{$code}-".random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => $seed->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
}

/** The PDF exactly as the owner downloads it. */
function downloadedPermit(Permit $permit): string
{
    authAs('owner@biztrack.local');

    return test()->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent();
}

function hasSignature(string $pdf): bool
{
    return str_contains($pdf, '/ByteRange') && str_contains($pdf, '/Contents <')
        && preg_match('#/SubFilter\s*/ETSI\.CAdES\.detached#', $pdf) === 1;
}

/**
 * pyHanko's reading of the one signature in $pdf — the validator behind
 * `pyhanko sign validate` — trusting the authority the .p12 carries, as a
 * reader trusts PNPKI's root.
 */
function pyhankoVerdict(string $pdf, string $p12, ?string $password = null): array
{
    openssl_pkcs12_read((string) file_get_contents($p12), $certs, (string) $password);
    $dir = dirname($p12);
    file_put_contents("{$dir}/signed.pdf", $pdf);
    file_put_contents("{$dir}/ca.pem", $certs['extracerts'][0]);

    $script = <<<'PY'
        import json, sys
        from pyhanko.keys import load_cert_from_pemder
        from pyhanko.pdf_utils.reader import PdfFileReader
        from pyhanko.sign.validation import validate_pdf_signature
        from pyhanko_certvalidator import ValidationContext

        with open(sys.argv[1], 'rb') as f:
            signatures = PdfFileReader(f).embedded_signatures
            sig = signatures[0]
            status = validate_pdf_signature(sig, ValidationContext(trust_roots=[load_cert_from_pemder(sys.argv[2])]))
            print(json.dumps({
                'signatures': len(signatures),
                'subfilter': str(sig.sig_object['/SubFilter']),
                'reason': str(sig.sig_object['/Reason']),
                'location': str(sig.sig_object['/Location']),
                'rect': [float(n) for n in sig.sig_field['/Rect']],
                'signed_at': sig.self_reported_timestamp.isoformat(),
                'md_algorithm': status.md_algorithm,
                'signer': status.signing_cert.subject.human_friendly,
                'intact': status.intact,
                'valid': status.valid,
                'trusted': status.trusted,
                'coverage': status.coverage.name,
                'bottom_line': status.bottom_line,
            }))
        PY;

    $run = Process::run([(string) config('permit_signing.python'), '-c', $script, "{$dir}/signed.pdf", "{$dir}/ca.pem"]);
    expect($run->successful())->toBeTrue($run->errorOutput());

    return json_decode($run->output(), true);
}

it('signs every permit PDF it serves, invisibly, with a PAdES signature pyHanko finds intact', function (string $code) {
    $this->artisan('biztrack:signing-certificate')->assertSuccessful();
    $permit = signablePermit($code);

    $pdf = downloadedPermit($permit);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(hasSignature($pdf))->toBeTrue()
        // The archive copy is the signed file, not the one before it.
        ->and(Storage::disk('local')->get("private/permits/{$permit->id}.pdf"))->toBe($pdf);

    $verdict = pyhankoVerdict($pdf, config('permit_signing.p12'));
    expect($verdict)
        ->signatures->toBe(1)
        ->subfilter->toBe('/ETSI.CAdES.detached')
        ->md_algorithm->toBe('sha256')
        ->signer->toBe('Common Name: BizTrack Permit Signing, Organization: BizTrack')
        ->reason->toBe('Permit issued through BizTrack')
        ->location->toBe('Malabon City')
        // No box, so nothing drawn: the field has no area on the page.
        ->rect->toBe([0.0, 0.0, 0.0, 0.0])
        ->intact->toBeTrue()
        ->valid->toBeTrue()
        ->trusted->toBeTrue()
        ->coverage->toBe('ENTIRE_FILE')
        ->bottom_line->toBeTrue();
    // Signed at the download, not at some fixed or cached moment.
    expect(abs(now()->diffInSeconds(Carbon::parse($verdict['signed_at']))))->toBeLessThan(120);
})->with(['BUSINESS', 'ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY'])
    ->skip(fn () => ! pyhankoInstalled(), 'pyHanko is not installed (docs/digital-signature.md).');

it('draws the signed permit exactly as the unsigned one', function () {
    $permit = signablePermit('BUSINESS');
    $unsigned = downloadedPermit($permit);
    $this->artisan('biztrack:signing-certificate')->assertSuccessful();

    $signed = downloadedPermit($permit);

    expect(hasSignature($unsigned))->toBeFalse()
        ->and(hasSignature($signed))->toBeTrue();
    $before = (new Parser)->parseContent($unsigned);
    $after = (new Parser)->parseContent($signed);
    expect($after->getPages())->toHaveCount(count($before->getPages()))
        ->and($after->getText())->toBe($before->getText());
})->skip(fn () => ! pyhankoInstalled(), 'pyHanko is not installed (docs/digital-signature.md).');

it('serves the permit unsigned and logs a warning when pyHanko cannot be run', function () {
    $this->artisan('biztrack:signing-certificate')->assertSuccessful();
    config(['permit_signing.python' => '/nonexistent/bin/python']);
    Log::spy();
    $permit = signablePermit('BUSINESS');

    $pdf = downloadedPermit($permit);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(hasSignature($pdf))->toBeFalse()
        ->and(Storage::disk('local')->get("private/permits/{$permit->id}.pdf"))->toBe($pdf);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'Permit PDF served unsigned: the signing tool failed.')
        ->once();
});

it('serves the permit unsigned and logs a warning when signing is on and the certificate is missing', function () {
    config(['permit_signing.enabled' => true]);
    Log::spy();

    $pdf = downloadedPermit(signablePermit('BUSINESS'));

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(hasSignature($pdf))->toBeFalse();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'Permit PDF served unsigned: there is no signing certificate.')
        ->once();
});

it('serves the permit unsigned and logs a warning when the certificate password is wrong', function () {
    config(['permit_signing.password' => 'the right one']);
    $this->artisan('biztrack:signing-certificate')->assertSuccessful();
    config(['permit_signing.password' => 'a wrong one']);
    Log::spy();

    $pdf = downloadedPermit(signablePermit('BUSINESS'));

    expect(hasSignature($pdf))->toBeFalse();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Permit PDF served unsigned: the signing tool failed.'
            && str_contains($context['error'], 'The signing certificate could not be read'))
        ->once();
})->skip(fn () => ! pyhankoInstalled(), 'pyHanko is not installed (docs/digital-signature.md).');

it('signs only once a certificate exists, and never when switched off, without a warning either way', function () {
    Log::spy();
    $permit = signablePermit('BUSINESS');

    // Unset and no certificate: a server that has not been given one.
    expect(hasSignature(downloadedPermit($permit)))->toBeFalse();

    // Switched off, with a certificate there.
    $this->artisan('biztrack:signing-certificate')->assertSuccessful();
    config(['permit_signing.enabled' => false]);
    expect(hasSignature(downloadedPermit($permit)))->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});

it('keeps the certificate in private storage by default', function () {
    expect((require config_path('permit_signing.php'))['p12'])
        ->toBe(storage_path('app/private/signing/permit-signing.p12'));
});

it('makes a development certificate named for BizTrack alone, and prints its fingerprint', function () {
    config(['permit_signing.password' => 'correct horse']);
    $p12 = config('permit_signing.p12');

    expect(Artisan::call('biztrack:signing-certificate'))->toBe(0);
    $output = Artisan::output();

    expect(fileperms($p12) & 0777)->toBe(0600)
        // Locked with the configured password, and only with it.
        ->and(openssl_pkcs12_read((string) file_get_contents($p12), $none, ''))->toBeFalse()
        ->and(openssl_pkcs12_read((string) file_get_contents($p12), $certs, 'correct horse'))->toBeTrue();

    $leaf = openssl_x509_parse($certs['cert']);
    $ca = openssl_x509_parse($certs['extracerts'][0]);
    expect($leaf['subject'])->toBe(['O' => 'BizTrack', 'CN' => 'BizTrack Permit Signing'])
        ->and($leaf['issuer'])->toBe(['O' => 'BizTrack', 'CN' => 'BizTrack Permit Signing CA'])
        ->and($leaf['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and($leaf['extensions']['keyUsage'])->toBe('Digital Signature, Non Repudiation')
        // A self-signed authority, and the key that matches the certificate.
        ->and($ca['subject'])->toBe($ca['issuer'])
        ->and($ca['extensions']['basicConstraints'])->toBe('CA:TRUE')
        ->and(openssl_x509_check_private_key($certs['cert'], $certs['pkey']))->toBeTrue();

    // Never the City's or a person's name.
    expect(strtolower(json_encode([$leaf['subject'], $ca['subject']])))->not->toContain('malabon');

    // The fingerprint printed is this certificate's, in the form a browser shows.
    $fingerprint = implode(':', str_split(strtoupper(openssl_x509_fingerprint($certs['cert'], 'sha256')), 2));
    expect($output)->toContain("SHA-256 fingerprint: {$fingerprint}");
});

it('makes the certificate once, and replaces it only with --force', function () {
    $p12 = config('permit_signing.p12');
    $this->artisan('biztrack:signing-certificate')->assertSuccessful();
    $first = file_get_contents($p12);

    $this->artisan('biztrack:signing-certificate')
        ->expectsOutputToContain('already exists')
        ->assertFailed();
    expect(file_get_contents($p12))->toBe($first);

    $this->artisan('biztrack:signing-certificate', ['--force' => true])->assertSuccessful();
    expect(file_get_contents($p12))->not->toBe($first);
});
