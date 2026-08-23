<?php

declare(strict_types=1);

return [

    /*
    | php    — pure PHP, no browser dependency, low memory. The default.
    | chrome — headless browser, for templates that need modern CSS.
    */
    'pdf' => [
        'renderer' => env('PDF_RENDERER', 'php'),
        'chrome_binary' => env('CHROME_BINARY', '/usr/bin/chromium'),
    ],

    /*
    | smtp — whatever the environment is configured with (Mailpit in development)
    | n8n  — hand off to a workflow that owns retries and provider choice
    */
    'mail' => [
        'provider' => env('MAIL_PROVIDER', 'smtp'),
        'n8n_webhook' => env('N8N_MAIL_WEBHOOK'),
        'n8n_secret' => env('N8N_SIGNING_SECRET'),
    ],

    /*
    | Where generated PDFs live. Tenant first in the path, so per-tenant export, lifecycle rules
    | and deletion are all trivial (SL-OPS-007 §4).
    */
    'storage' => [
        'disk' => env('REPORTS_DISK', env('FILESYSTEM_DISK', 'local')),
    ],

    /*
    | The blocks a new tenant's default template carries. Order is display order.
    */
    'default_blocks' => ['summary', 'engagement', 'highlights', 'work', 'notes'],

    /*
    | Sending a report over an incomplete period misleads whoever reads it, so it is refused
    | unless someone deliberately overrides.
    */
    'require_complete_period' => true,
];
