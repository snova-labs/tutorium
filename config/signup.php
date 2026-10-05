<?php

declare(strict_types=1);

return [

    /*
    | Long enough to run a real reporting period, which is what a customer actually needs to
    | evaluate this: a fortnight covers a month-end for most academies that start mid-month.
    */
    'trial_days' => env('TRIAL_DAYS', 14),

    'invitation_days' => env('INVITATION_DAYS', 7),

    /*
    | Public signup can be closed without a deploy — useful during a controlled rollout, or if
    | abuse ever makes it necessary.
    */
    'open' => env('SIGNUP_OPEN', true),

    /*
    | Nothing is provisioned until the owner confirms their address (SL-402). The emailed link is
    | single-use and expires after this many hours.
    */
    'confirmation_hours' => env('SIGNUP_CONFIRMATION_HOURS', 48),

    /*
    | Throttling, per rolling hour (SL-401). Every submission counts, accepted or not.
    |
    | Per domain catches one organisation (or one throwaway domain) creating account after account
    | from many networks. Shared mailbox providers are exempt from the domain limit, because a
    | limit on gmail.com is a limit on everyone; the address and network limits still apply.
    */
    'limits' => [
        'per_email' => env('SIGNUP_LIMIT_PER_EMAIL', 3),
        'per_ip' => env('SIGNUP_LIMIT_PER_IP', 10),
        'per_domain' => env('SIGNUP_LIMIT_PER_DOMAIN', 5),
    ],

    'shared_mail_domains' => [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'yahoo.com', 'ymail.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me',
        'protonmail.com', 'gmx.com', 'gmx.net', 'mail.com', 'zoho.com', 'yandex.com',
    ],

    /*
    | Trial reminders go out on these days of the trial (SL-403): day 7, day 12, and the last day.
    | Counted from the start, so for a 14-day trial they arrive with 8, 3 and 1 days to go
    | (the current day included).
    */
    'trial_reminder_days' => [7, 12, 14],

    /*
    | Outbound email to families is held until an address on the account is confirmed. Owners are
    | confirmed by signing up; this still covers accounts created any other way.
    */
    'require_verification_to_send' => true,
];
