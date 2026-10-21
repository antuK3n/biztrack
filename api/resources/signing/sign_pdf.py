"""
Sign one permit PDF for BizTrack.

    python sign_pdf.py --p12 <file> --reason <text> --location <text> < in.pdf > out.pdf

App\\Support\\PermitSignature runs this for every permit PDF it serves; the
setup and the PNPKI swap are in docs/digital-signature.md.

The PDF comes in on stdin and goes out on stdout, so no copy of a permit is
written to a temporary file. The certificate's password, when it has one,
comes in the PERMIT_SIGNING_PASSWORD environment variable and never on the
command line, where `ps` would show it to every account on the server.

What it writes:

  - a PAdES baseline signature (SubFilter /ETSI.CAdES.detached, SHA-256),
    carrying the signer's X.509 certificate and the chain the .p12 holds, so
    a reader can check it without fetching anything
  - in an invisible field: no box and no stamp, so the permit looks exactly
    as dompdf drew it
  - as an incremental update: dompdf's bytes stay as they were and the
    signature covers every one of them, which is how Acrobat can say the
    document has not been modified since it was signed
  - with the reason and location it is given; the signing time is now

Exit status 0 means stdout is the signed PDF. Anything else means it is not,
stderr says why, and the caller serves the unsigned PDF instead.
"""

import argparse
import io
import os
import sys

FIELD_NAME = 'BizTrackSignature'


def main() -> int:
    parser = argparse.ArgumentParser(description='Sign a BizTrack permit PDF read from stdin.')
    parser.add_argument('--p12', required=True, help='PKCS#12 file holding the signing key and certificate')
    parser.add_argument('--reason', required=True)
    parser.add_argument('--location', required=True)
    args = parser.parse_args()

    # Anything, not just ImportError: pyHanko looks up a time zone as it
    # loads, and fails with a KeyError where there is no zone database
    # (requirements.txt carries tzdata for that). One line either way.
    try:
        from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
        from pyhanko.sign import fields, signers
    except Exception as error:
        print(f'pyHanko could not be loaded by {sys.executable}: {error!r}', file=sys.stderr)
        return 3

    password = os.environ.get('PERMIT_SIGNING_PASSWORD') or None
    # load_pkcs12_data rather than load_pkcs12: the latter logs a traceback
    # and answers None for a wrong password, where this raises with a reason.
    try:
        with open(args.p12, 'rb') as p12:
            signer = signers.SimpleSigner.load_pkcs12_data(
                p12.read(), other_certs=(), passphrase=password.encode() if password else None
            )
    except (OSError, ValueError) as error:  # no file, a wrong password, not a PKCS#12
        print(f'The signing certificate could not be read: {error}', file=sys.stderr)
        return 4

    unsigned = sys.stdin.buffer.read()
    try:
        writer = IncrementalPdfFileWriter(io.BytesIO(unsigned))
        signed = signers.sign_pdf(
            writer,
            signers.PdfSignatureMetadata(
                field_name=FIELD_NAME,
                md_algorithm='sha256',
                subfilter=fields.SigSeedSubFilter.PADES,
                reason=args.reason,
                location=args.location,
            ),
            signer=signer,
            # No box: an invisible field, so nothing is drawn on the permit.
            new_field_spec=fields.SigFieldSpec(sig_field_name=FIELD_NAME),
        )
    except Exception as error:
        print(f'The PDF could not be signed: {error}', file=sys.stderr)
        return 5

    sys.stdout.buffer.write(signed.getbuffer())
    sys.stdout.buffer.flush()
    return 0


if __name__ == '__main__':
    sys.exit(main())
