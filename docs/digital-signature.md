# Digitally signed permits

Every permit PDF BizTrack serves (the Mayor's Permit and the five office
certificates: Zoning, Sanitary, FSIC, CENRO, Occupancy) carries a digital
signature, so anyone holding the file can check that BizTrack issued it and
that nobody has changed it since (revision matrix item 7).

- **Standard:** PAdES baseline (SubFilter `ETSI.CAdES.detached`), X.509
  certificate, SHA-256. The signer's certificate and its chain travel inside
  the file.
- **Invisible:** no box and no stamp. The permit renders exactly as it did
  before signing, pixel for pixel.
- **What it says:** reason *Permit issued through BizTrack*, location
  *Malabon City*, signed at the moment of download.
- **Built for PNPKI:** a certificate from DICT replaces the development one
  without a code change (below).

## Checking a permit

Open the PDF in Adobe Acrobat Reader and open the **Signature panel**. It
shows the permit signed by *BizTrack Permit Signing* and *"Document has not
been modified since this signature was applied."* Change one byte and the
panel reports it.

With the development certificate, Acrobat also says the signer's identity is
**unknown**: we made that certificate ourselves, and no reader trusts it. That
is the part a PNPKI certificate changes, in readers that trust the PNPKI root.
To demonstrate a trusted signature with the development certificate, extract
its authority and add it in Acrobat under *Preferences › Signatures › Identities
& Trusted Certificates*:

```bash
openssl pkcs12 -in permit-signing.p12 -cacerts -nokeys -out biztrack-dev-ca.pem
```

## How it works

`App\Support\PermitSignature` hands each rendered permit to
`api/resources/signing/sign_pdf.py`, which signs it with
[pyHanko](https://github.com/MatthiasValvekens/pyHanko) (MIT), pinned with
its dependencies in `api/resources/signing/requirements.txt`. The app image
installs them into `/opt/pyhanko` (`infra/azure/app.Dockerfile`). The image
for the MISD box, `infra/php/Dockerfile`, does not, so permits served from it
go out unsigned.

**A signature never costs a download.** If signing is on and the certificate
is missing, or pyHanko is missing, the password is wrong, or a run fails or
takes over 15 seconds, the permit is served unsigned, exactly as before, and
the Laravel log gets a warning that starts *Permit PDF served unsigned*.

Signing adds about half a second to a download (0.2 s unsigned against 0.6 to
0.8 s signed, measured on a laptop). Nothing is cached: every download is
drawn and signed afresh, so a permit whose status changed is never served
with an old signature.

## Configuration

| Key | Default | |
|---|---|---|
| `PERMIT_SIGNING_ENABLED` | unset: sign whenever the certificate exists | `true` also logs a warning when it does not; `false` turns signing off |
| `PERMIT_SIGNING_P12` | `storage/app/private/signing/permit-signing.p12` | the PKCS#12 file; a relative path is read from `api/` |
| `PERMIT_SIGNING_PASSWORD` | none | the `.p12` password |
| `PERMIT_SIGNING_PYTHON` | `/opt/pyhanko/bin/python` in the image; `api/.venv/bin/python` if it exists; else `python3` | the Python that has pyHanko |

Private storage is never served by the web server and never committed. It is
in the nightly backup, with the rest of private storage, on the server's own
disk.

## The development certificate

```bash
php artisan biztrack:signing-certificate          # makes one if there is none
php artisan biztrack:signing-certificate --force  # replaces the one there
```

It makes a self-signed authority (*BizTrack Permit Signing CA*) and a signing
certificate it issues (CN *BizTrack Permit Signing*, O *BizTrack*), stores
them with the key at `PERMIT_SIGNING_P12` (locked with
`PERMIT_SIGNING_PASSWORD` if set), and prints the certificate's SHA-256
fingerprint. It names BizTrack only, never the City or an officer: a
certificate we made that claimed to be either would be a forgery of what PNPKI
vouches for. It refuses to replace an existing file without `--force`,
because by then that file may be the real one.

On the Azure server, run it through the app image's entrypoint, so the file
belongs to the user PHP runs as:

```bash
cd ~/biztrack/infra/azure
docker compose run --rm app php artisan biztrack:signing-certificate
```

(`docker compose exec` would write it as root, and PHP could not read it until
the next restart.) Signing starts with the next download.

## Swapping in a PNPKI certificate

1. Get the signing certificate from DICT's PNPKI as a PKCS#12 file (`.p12` or
   `.pfx`) holding the private key, ideally with the PNPKI chain inside. Whose
   name it carries, the City's office or an officer's, is the City's decision.
2. Copy it to the server's private storage, replacing the development file at
   `storage/app/private/signing/permit-signing.p12`, or put it anywhere outside
   the web root and point `PERMIT_SIGNING_P12` at it.
3. Set `PERMIT_SIGNING_PASSWORD` (and `PERMIT_SIGNING_P12`, if it moved) in
   `api/.env.production`, then recreate the containers so they read it:
   `docker compose up -d --force-recreate`. Each caches its configuration
   again as it starts.
4. Download a permit and open its Signature panel: the signer is now the name
   on the PNPKI certificate.

Older PKCS#12 encryption (RC2, 3DES) is read as well as the current kind.

## Not included

The signature carries no timestamp from a timestamping authority and no
revocation data (PAdES B-B). The signing time is the server's clock. If the
City wants signatures that stay verifiable after the certificate expires,
pyHanko can add a timestamp (B-T) and validation data (B-LT); that needs a
timestamping service to call.

## Local development

```bash
cd api
python3 -m venv .venv                                   # gitignored; found without configuration
.venv/bin/pip install -r resources/signing/requirements.txt
php artisan biztrack:signing-certificate
```

`PermitDigitalSignatureTest` signs with this venv and checks every signature
with pyHanko's validator; without it those tests skip. The rest of the suite
runs with signing off (`phpunit.xml`).
