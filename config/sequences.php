<?php

declare(strict_types=1);

return [

    /*
    | Fallback formats used when a tenant has no configured sequence for an
    | entity. Onboarding presets normally create these; these defaults exist so
    | that a missing settings row can never prevent a record from being saved.
    */

    'defaults' => [
        'learner' => ['prefix' => 'STU', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        'enrollment' => ['prefix' => 'ENR', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        'assessment' => ['prefix' => 'ASM', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        'report' => ['prefix' => 'RPT', 'separator' => '-', 'pad_width' => 5, 'start' => 1],
        'invoice' => ['prefix' => 'INV', 'separator' => '-', 'pad_width' => 5, 'start' => 1],
        'fallback' => ['prefix' => 'REC', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
    ],
];
