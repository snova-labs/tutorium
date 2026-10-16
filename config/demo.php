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
    | SEED_DEMO=true in a stack's environment variables: the deploy builds the demo academies
    | (platform:deploy). They are built once and the flag is then yours to turn off; like the demo
    | page, this never runs with APP_ENV=production.
    */
    'seed_on_deploy' => filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Build only these academies (DemoProfiles slugs). Empty for all four; tests use one.
    */
    'only' => [],
];
