<?php

declare(strict_types=1);

return [

    /*
    | stripe — cards, via a hosted page we never see details on
    | manual — invoicing, for customers who pay by bank transfer
    | fake   — for tests and local development; never in production
    */
    'provider' => env('BILLING_PROVIDER', 'manual'),

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    | Days after the first failure on which collection is retried. Published to the customer
    | before anything happens to them, and applied identically to everyone.
    */
    'dunning' => [
        'schedule' => [1, 3, 5, 7],
        'suspend_after_final_failure' => true,
    ],

    /*
    | next_period — an upgrade grants features now and charges the new rate next period
    | immediate   — the new rate applies from the moment of the change
    |
    | The default is deliberate: with a period-based metric there is no partial quantity to
    | apportion, and a few days of a better plan at the old rate is cheaper than the disputes
    | that proration arithmetic generates.
    */
    'plan_change_pricing' => env('PLAN_CHANGE_PRICING', 'next_period'),
];
