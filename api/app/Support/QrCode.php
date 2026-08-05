<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as QrLib;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders a QR code as an SVG data-URI (pure PHP, no GD/Imagick) so it embeds
 * cleanly in dompdf.
 *
 * The output backend is selected by class (`outputInterface`), not by the
 * `outputType` constant this used to pass: chillerlan/php-qrcode dropped
 * QROutputInterface::MARKUP_SVG in v5, so on the installed v6 that constant
 * raised an Error, the catch below swallowed it, and every certificate printed
 * with no QR at all (tester item 73). A failure is logged now so the next
 * upstream rename is visible instead of silent.
 */
class QrCode
{
    /** @return string data-URI (image/svg+xml;base64,...) or '' on failure. */
    public static function svgDataUri(string $text): string
    {
        try {
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'eccLevel' => EccLevel::M,
                // Quiet zone included: scanners need the 4-module margin.
                'addQuietzone' => true,
                'quietzoneSize' => 4,
                // We wrap the markup in the data-URI ourselves, below.
                'outputBase64' => false,
            ]);

            $svg = (new QrLib($options))->render($text);

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (Throwable $e) {
            Log::warning('QR code render failed', ['text' => $text, 'error' => $e->getMessage()]);

            return '';
        }
    }
}
