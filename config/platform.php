<?php

declare(strict_types=1);

return [

    /*
    | The public product name. Deliberately the ONLY place it appears outside
    | language files, so a rename never becomes a refactor.
    */
    'name' => env('PLATFORM_NAME', 'Platform'),

    /*
    | cloud       — multi-tenant SaaS; entitlements resolve from subscriptions
    | self_hosted — single tenant; entitlements resolve from a signed licence file
    */
    'mode' => env('PLATFORM_MODE', 'cloud'),

    /*
    | Where people use the product: the staff client (web/). Links in emails point here. Falls back
    | to APP_URL when the API and the client share an address.
    */
    'web_url' => env('WEB_URL', env('APP_URL')),

    /*
    | The first academy, created by the server itself.
    |
    | A brand new environment has a database and no academy, so nobody can sign in to make one.
    | Set these in the stack's environment variables and the deploy creates it: the migrate step
    | runs `platform:first-run`, which does nothing at all unless `academy` is filled in, and
    | nothing a second time once that academy exists. No clone, no shell, nothing to type.
    |
    | Leave `password` empty and one is generated and printed in the migrate container's log —
    | readable in Portainer, and only there. Set it to choose your own instead.
    */
    'first_run' => [
        'academy' => env('FIRST_RUN_ACADEMY', ''),
        'owner_name' => env('FIRST_RUN_OWNER_NAME', ''),
        'owner_email' => env('FIRST_RUN_OWNER_EMAIL', ''),
        'password' => env('FIRST_RUN_PASSWORD', ''),
        'timezone' => env('FIRST_RUN_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
        'preset' => env('FIRST_RUN_PRESET', 'blank'),
    ],

    'pdf' => [
        // php    — pure PHP renderer; no browser dependency, low memory
        // chrome — headless browser; modern CSS, ~700MB more RAM
        'renderer' => env('PDF_RENDERER', 'php'),
    ],

    'billing' => [
        'provider' => env('BILLING_PROVIDER', 'manual'),
    ],
];
