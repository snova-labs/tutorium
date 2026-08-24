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
    | Verification gates outbound email to families, not the product. Someone can build a
    | timetable and take a register before confirming their address; what they cannot do is send
    | to a parent from an address nobody has proved they own.
    */
    'require_verification_to_send' => true,
];
