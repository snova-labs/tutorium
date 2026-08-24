<?php

declare(strict_types=1);

namespace App\Support\Pdf;

final class PdfOptions
{
    public function __construct(
        public readonly string $paper = 'a4',
        public readonly string $orientation = 'portrait',
        public readonly ?string $title = null,
    ) {}
}
