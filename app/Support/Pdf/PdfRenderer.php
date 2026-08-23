<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * Turns HTML into PDF bytes.
 *
 * An interface with two drivers rather than a direct dependency, because the pure-PHP renderer
 * costs almost nothing and the headless browser costs roughly 700MB of memory. Starting on the
 * cheap one and upgrading when a layout demands it is a configuration change (ADR-003).
 */
interface PdfRenderer
{
    public function render(string $html, PdfOptions $options): string;

    public function name(): string;
}
