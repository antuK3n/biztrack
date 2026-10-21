<?php

/*
 * Digitally signed permits (revision matrix item 7; docs/digital-signature.md).
 *
 * Every permit PDF BizTrack serves — the Mayor's Permit and the five office
 * certificates — is signed with the certificate below as it is downloaded, so
 * a reader can check in Acrobat's signature panel that it came from BizTrack
 * and has not been changed since. App\Support\PermitSignature does it, through
 * api/resources/signing/sign_pdf.py (pyHanko).
 *
 * The signing is built for PNPKI. A certificate from DICT drops in by
 * replacing the .p12 file (or pointing PERMIT_SIGNING_P12 at it) and setting
 * PERMIT_SIGNING_PASSWORD. Nothing in the code names the certificate.
 */

$enabled = env('PERMIT_SIGNING_ENABLED');

return [

    /*
     * Unset (null) signs whenever the certificate file exists, so creating one
     * with `php artisan biztrack:signing-certificate` is all it takes to turn
     * signing on, and a server without one serves permits as it always has
     * without logging anything. true insists: a missing certificate is then
     * logged on every download. false turns signing off.
     */
    'enabled' => $enabled === null || $enabled === '' ? null : filter_var($enabled, FILTER_VALIDATE_BOOL),

    /*
     * The PKCS#12 file holding the signing key and its certificate chain. In
     * private storage by default, which the web server never serves and git
     * never tracks (storage/app/private/.gitignore). A relative path is read
     * from api/.
     */
    'p12' => env('PERMIT_SIGNING_P12') ?: storage_path('app/private/signing/permit-signing.p12'),

    // The .p12's password. The development certificate is made with this one,
    // or with none when it is unset.
    'password' => env('PERMIT_SIGNING_PASSWORD') ?: null,

    /*
     * The Python that has pyHanko installed. The app image sets it to its own
     * venv (infra/azure/app.Dockerfile). On a developer's machine the venv
     * docs/digital-signature.md describes, api/.venv, is found without
     * setting anything.
     */
    'python' => env('PERMIT_SIGNING_PYTHON')
        ?: (is_file(base_path('.venv/bin/python')) ? base_path('.venv/bin/python') : 'python3'),

    // What the signature says [Ken, revision matrix item 7]. Its time is always
    // the moment of signing.
    'reason' => 'Permit issued through BizTrack',
    'location' => 'Malabon City',

    /*
     * Seconds before a signing run is abandoned and the permit served
     * unsigned. A signature takes about half a second; this only stops a
     * stuck run from holding a download open.
     */
    'timeout' => 15,

];
