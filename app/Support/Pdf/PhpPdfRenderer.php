<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Pure-PHP rendering. The default, and enough for a report built from headings, tables and text.
 *
 * Remote resources are deliberately off: a report template is tenant-editable content, and an
 * HTML-to-PDF renderer that fetches arbitrary URLs is a request-forgery hole pointed at the
 * inside of our own network.
 */
final class PhpPdfRenderer implements PdfRenderer
{
    public function render(string $html, PdfOptions $options): string
    {
        $config = new Options;
        $config->set('isRemoteEnabled', false);
        $config->set('isHtml5ParserEnabled', true);
        $config->set('defaultFont', 'DejaVu Sans');
        $config->set('chroot', storage_path('app'));

        $dompdf = new Dompdf($config);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($options->paper, $options->orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function name(): string
    {
        return 'php';
    }
}
