<?php

declare(strict_types=1);

return [

    /*
    | A self-hosted install is told what it may do by a signed licence file. Nothing here reaches
    | the internet: the file is verified against a public key shipped with the release, so an
    | install behind an air gap, or one whose vendor has gone quiet for a week, keeps working
    | exactly as it did yesterday (NFR-PORT-2).
    */

    // Empty rather than unset counts as unset: an env file with the line left blank should mean
    // the default, not a licence at the path "".
    'path' => env('LICENCE_FILE') ?: storage_path('app/licence.json'),

    /*
    | The signing key's public half, as 64 hex characters. Baked into a release build; an empty
    | value means this build trusts no licence at all, which is the right answer for a build that
    | was never given a key — better than one that accepts anything.
    */
    'public_key' => env('LICENCE_PUBLIC_KEY', ''),

    /*
    | How long a licence keeps working after it expires. A renewal that is late by a day must
    | never take an academy's register away in the middle of a lesson; after the grace period the
    | install stops taking new enrollments and keeps every export.
    */
    'grace_days' => (int) (env('LICENCE_GRACE_DAYS') ?: 30),

    /*
    | How full the learner cap has to be before the product starts saying so. The point of a
    | warning is to arrive while there is still time to do something about it.
    */
    'warn_at_percent' => (int) (env('LICENCE_WARN_AT_PERCENT') ?: 90),

];
