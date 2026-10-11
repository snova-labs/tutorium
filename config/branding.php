<?php

declare(strict_types=1);

return [

    /*
    | A brand's logo. Kept on a private disk and served through an authenticated endpoint: a
    | public URL would let anyone who guessed it enumerate the academies on the platform.
    |
    | Changing the disk does not move files already stored, so move them yourself if you do.
    */
    'logo' => [
        'disk' => env('BRANDING_DISK', env('FILESYSTEM_DISK', 'local')),

        // Big enough for a crisp logo on a report, small enough that nobody waits for it.
        'max_kb' => 512,
        'max_pixels' => 2000,
    ],

];
