<?php

namespace App\Console\Commands;

use App\Support\PermitSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Make the development certificate permits are signed with, when there is none.
 *
 * Revision matrix item 7: issued permits are digitally signed, and the signing
 * is built for a PNPKI certificate from DICT, which BizTrack has not been
 * issued. Until it is, this makes a stand-in of the same shape — a self-signed
 * certificate authority, a signing certificate it issues, and the key, packed
 * into the PKCS#12 file PERMIT_SIGNING_P12 names — so the PNPKI file can later
 * take its place with no code change (docs/digital-signature.md).
 *
 * Named for BizTrack and nothing else: Subject CN "BizTrack Permit Signing",
 * O "BizTrack". Never the City's name or an officer's — a certificate we made
 * ourselves that claimed to be the City of Malabon, or a person, would be a
 * forgery of exactly what PNPKI exists to vouch for.
 *
 * The authority's key is discarded once it has signed, so nothing else can be
 * issued under it. And this refuses to replace a certificate that is already
 * there without --force: by then the file at that path may be the real one.
 */
class SigningCertificate extends Command
{
    protected $signature = 'biztrack:signing-certificate {--force : Replace the certificate that is already there}';

    protected $description = 'Create a development certificate to sign permit PDFs with, if there is none (see docs/digital-signature.md)';

    private const SUBJECT = ['organizationName' => 'BizTrack', 'commonName' => 'BizTrack Permit Signing'];

    private const CA_SUBJECT = ['organizationName' => 'BizTrack', 'commonName' => 'BizTrack Permit Signing CA'];

    /** Five years for the signing certificate, ten for the authority above it. */
    private const DAYS = 1825;

    private const CA_DAYS = 3650;

    /*
     * openssl_csr_sign takes its extensions from a config file and nowhere
     * else, so this is written to a temporary one for the length of the run.
     * digitalSignature and nonRepudiation are what a document-signing
     * certificate carries, PNPKI's included; the key identifiers let a
     * validator link the certificate to the authority that issued it.
     */
    private const OPENSSL_CONFIG = <<<'CNF'
        [req]
        distinguished_name = req_dn
        [req_dn]
        [biztrack_ca]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash
        [biztrack_signing]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, nonRepudiation
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid:always
        CNF;

    public function handle(): int
    {
        $path = PermitSignature::certificatePath();
        if (is_file($path) && ! $this->option('force')) {
            $this->error("A signing certificate already exists at {$path}. Run with --force to replace it.");

            return self::FAILURE;
        }

        $config = tempnam(sys_get_temp_dir(), 'biztrack-signing-');
        file_put_contents($config, self::OPENSSL_CONFIG);
        try {
            [$certificate, $p12] = $this->make($config);
        } finally {
            unlink($config);
        }
        if ($p12 === null) {
            // The last reason OpenSSL queued: ahead of it there is usually a
            // harmless complaint that ~/.rnd does not exist.
            $reasons = [];
            while (($reason = openssl_error_string()) !== false) {
                $reasons[] = $reason;
            }
            $this->error('The certificate could not be made: '.(end($reasons) ?: 'OpenSSL gave no reason.'));

            return self::FAILURE;
        }

        // Written beside the target and renamed into place, so a download in
        // the same second reads the old file or the new one, never half of one.
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $partial = $directory.'/.'.Str::random(12).'.p12';
        file_put_contents($partial, $p12);
        chmod($partial, 0600);
        rename($partial, $path);

        $this->info("Signing certificate created: {$path}");
        $this->line('Subject: CN='.self::SUBJECT['commonName'].', O='.self::SUBJECT['organizationName']);
        $this->line('SHA-256 fingerprint: '.implode(':', str_split(strtoupper(openssl_x509_fingerprint($certificate, 'sha256')), 2)));

        return self::SUCCESS;
    }

    /**
     * The signing certificate and the PKCS#12 bytes, or a null pair when
     * OpenSSL refuses a step (its reason is left on openssl_error_string()).
     *
     * @return array{0: \OpenSSLCertificate|null, 1: string|null}
     */
    private function make(string $config): array
    {
        $options = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ];

        $caKey = openssl_pkey_new($options);
        $caRequest = $caKey ? openssl_csr_new(self::CA_SUBJECT, $caKey, $options) : false;
        $ca = $caRequest ? openssl_csr_sign($caRequest, null, $caKey, self::CA_DAYS, $options + ['x509_extensions' => 'biztrack_ca'], random_int(1, PHP_INT_MAX)) : false;

        $key = $ca ? openssl_pkey_new($options) : false;
        $request = $key ? openssl_csr_new(self::SUBJECT, $key, $options) : false;
        $certificate = $request ? openssl_csr_sign($request, $ca, $caKey, self::DAYS, $options + ['x509_extensions' => 'biztrack_signing'], random_int(1, PHP_INT_MAX)) : false;

        $exported = $certificate && openssl_pkcs12_export($certificate, $p12, $key, (string) config('permit_signing.password'), [
            'extracerts' => [$ca],
            'friendly_name' => 'BizTrack Permit Signing',
        ]);

        return $exported ? [$certificate, $p12] : [null, null];
    }
}
