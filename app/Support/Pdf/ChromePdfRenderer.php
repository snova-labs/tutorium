<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Headless-browser rendering, for templates that need modern CSS.
 *
 * Shells out to Chrome directly rather than pulling in a wrapper package, so a self-hosted
 * installation can point at whatever browser binary it already has. Enabled with
 * PDF_RENDERER=chrome once the image includes one.
 */
final class ChromePdfRenderer implements PdfRenderer
{
    public function __construct(private readonly string $binary) {}

    public function render(string $html, PdfOptions $options): string
    {
        $htmlFile = tempnam(sys_get_temp_dir(), 'report_').'.html';
        $pdfFile = tempnam(sys_get_temp_dir(), 'report_').'.pdf';

        file_put_contents($htmlFile, $html);

        try {
            $process = new Process([
                $this->binary,
                '--headless', '--disable-gpu', '--no-sandbox',
                '--print-to-pdf-no-header',
                '--print-to-pdf='.$pdfFile,
                'file://'.$htmlFile,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($pdfFile)) {
                throw new RuntimeException('Chrome could not render the report: '.$process->getErrorOutput());
            }

            return (string) file_get_contents($pdfFile);
        } finally {
            @unlink($htmlFile);
            @unlink($pdfFile);
        }
    }

    public function name(): string
    {
        return 'chrome';
    }
}
