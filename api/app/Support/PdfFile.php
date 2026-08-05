<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PdfFile
{
    public function __construct(public readonly string $content) {}

    public static function render(PDF $pdf): self
    {
        return new self($pdf->output());
    }

    public function download(string $filename): Response
    {
        $fallback = str_replace('%', '', Str::ascii($filename));

        return new Response($this->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, $fallback),
            'Content-Length' => (string) strlen($this->content),
        ]);
    }
}
