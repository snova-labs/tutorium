<?php

declare(strict_types=1);

return [

    /*
    | The demonstration academies (php artisan platform:demo, and the /demo page of the staff
    | client). The password unlocks the page and becomes every demo account's password. Leave it
    | empty to switch the page off. Nothing here ever runs with APP_ENV=production.
    */
    'password' => env('DEMO_PASSWORD', ''),

    /*
    | Build only these academies (DemoProfiles slugs). Empty for all four; tests use one.
    */
    'only' => [],
];
