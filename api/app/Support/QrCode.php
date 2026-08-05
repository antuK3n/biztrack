<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as QrLib;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Log;
use Throwable;

class QrCode
{
    public static function svgDataUri(string $text): string
    {
        try {
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'eccLevel' => EccLevel::M,

                'addQuietzone' => true,
                'quietzoneSize' => 4,

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
