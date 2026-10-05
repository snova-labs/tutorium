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

    'pdf' => [
        // php    — pure PHP renderer; no browser dependency, low memory
        // chrome — headless browser; modern CSS, ~700MB more RAM
        'renderer' => env('PDF_RENDERER', 'php'),
    ],

    'billing' => [
        'provider' => env('BILLING_PROVIDER', 'manual'),
    ],
];
