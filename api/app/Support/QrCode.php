<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as QrLib;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Renders a QR code as an SVG data-URI (pure PHP, no GD/Imagick) so it embeds
 * cleanly in dompdf. Falls back to a prominent text block if the lib fails.
 */
class QrCode
{
    /** @return string data-URI (image/svg+xml;base64,...) or '' on failure. */
    public static function svgDataUri(string $text): string
    {
        try {
            $options = new QROptions([
                'outputType' => QROutputInterface::MARKUP_SVG,
                'eccLevel' => EccLevel::M,
                'svgViewBoxSize' => 0,
                'imageBase64' => false,
                'scale' => 5,
            ]);

            $svg = (new QrLib($options))->render($text);

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (Throwable $e) {
            return '';
        }
    }
}
