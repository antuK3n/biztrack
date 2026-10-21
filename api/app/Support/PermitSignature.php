<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Digitally sign a permit PDF as it is served (revision matrix item 7: "Issued
 * permits are now digitally signed, so their authenticity can be verified.
 * The signing is designed to use PNPKI certificates.").
 *
 * The signature is a standard one — PAdES baseline, X.509, SHA-256 — so
 * Acrobat's signature panel can say who signed the permit and that it has not
 * been changed since. It is invisible: no box, no stamp, the permit looks
 * exactly as it did. The work is done by pyHanko through
 * api/resources/signing/sign_pdf.py, which says what it writes; the
 * certificate and the switch are in config/permit_signing.php, and the PNPKI
 * swap in docs/digital-signature.md.
 *
 * ── Why pyHanko, and not a PHP library ──────────────────────────────────────
 *
 * No PHP library found (October 2026) writes a PAdES signature onto an
 * existing PDF under a licence that fits. TCPDF signs only a document TCPDF
 * itself draws, so a permit would have to be re-imported page by page (FPDI)
 * and would no longer be the file dompdf wrote. SAPP does sign an existing
 * file in place, but fixes its SubFilter at adbe.pkcs7.detached, the older
 * format PAdES replaced with ETSI.CAdES.detached. SetaPDF-Signer does it
 * properly and is commercial. pyHanko (MIT) is the reference open
 * implementation of PAdES, and its validator is how the tests prove a
 * signature holds.
 *
 * ── A signature never costs a download ──────────────────────────────────────
 *
 * When the certificate is missing, the tool is missing, or a run fails, the
 * permit is served unsigned, exactly as dompdf drew it, and a warning is
 * logged. An unsigned permit is what every download was before this; a failed
 * download is worse than either.
 */
final class PermitSignature
{
    /** The PKCS#12 file. A relative PERMIT_SIGNING_P12 is read from api/. */
    public static function certificatePath(): string
    {
        return self::fromApi((string) config('permit_signing.p12'));
    }

    /**
     * The same permit, signed — or exactly as given when signing is off or
     * cannot be done.
     */
    public static function sign(PdfFile $file): PdfFile
    {
        $enabled = config('permit_signing.enabled');
        if ($enabled === false) {
            return $file;
        }

        $p12 = self::certificatePath();
        if (! is_file($p12)) {
            // Unset means "sign when there is a certificate", so a server
            // without one is not news. Only an explicit true expects one.
            if ($enabled === true) {
                Log::warning('Permit PDF served unsigned: there is no signing certificate.', ['p12' => $p12]);
            }

            return $file;
        }

        try {
            $result = Process::timeout((int) config('permit_signing.timeout', 15))
                ->input($file->content)
                // In the environment, never on the command line, where `ps`
                // would show it; false removes one inherited from the server.
                ->env(['PERMIT_SIGNING_PASSWORD' => config('permit_signing.password') ?? false])
                ->run([
                    self::python(),
                    resource_path('signing/sign_pdf.py'),
                    '--p12', $p12,
                    '--reason', (string) config('permit_signing.reason'),
                    '--location', (string) config('permit_signing.location'),
                ]);
        } catch (Throwable $e) {
            // A timeout, or a server that cannot start a process at all.
            Log::warning('Permit PDF served unsigned: the signing tool did not finish.', ['error' => $e->getMessage()]);

            return $file;
        }

        /*
         * The signature is an incremental update: it only appends. A result
         * that does not begin with every byte dompdf wrote is not the permit
         * that was drawn, whatever exit status came with it, and one that adds
         * nothing is not signed.
         */
        $signed = $result->output();
        if (! $result->successful() || strlen($signed) <= strlen($file->content) || ! str_starts_with($signed, $file->content)) {
            Log::warning('Permit PDF served unsigned: the signing tool failed.', [
                'exit_code' => $result->exitCode(),
                'error' => Str::limit(trim($result->errorOutput()), 500),
            ]);

            return $file;
        }

        return new PdfFile($signed);
    }

    /** The interpreter: a bare name is looked up on PATH, a relative path read from api/. */
    private static function python(): string
    {
        $python = (string) config('permit_signing.python');

        return str_contains($python, '/') ? self::fromApi($python) : $python;
    }

    private static function fromApi(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
