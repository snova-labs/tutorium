<?php

declare(strict_types=1);

return [

    /*
    | System defaults — the last link in the resolution chain
    | (batch → course → branch → brand → tenant → preset → here).
    |
    | Every value here is deliberately conservative. Presets override them per
    | vertical and country; nothing in this file assumes a locale.
    */

    'defaults' => [
        // People
        // Switched off by adult-only academies, where the concept is noise rather than absence.
        'people.guardians_enabled' => true,
        'people.require_guardian_for_minors' => true,
        'people.duplicate_check_enabled' => true,

        // Attendance
        'attendance.compulsory' => true,
        'attendance.allow_late_join' => true,
        'attendance.late_grace_minutes' => 10,
        'attendance.low_threshold_pct' => 75,

        // Grading
        'grading.default_scheme' => 'points',
        'grading.weighted_averages' => true,

        // Periods
        'periods.type' => 'monthly',

        // Locale and time
        'locale.calendar' => 'gregorian',
        'locale.week_start' => 'monday',
        'locale.weekend_days' => ['saturday', 'sunday'],

        // Reporting
        'reports.include_notes' => true,
        'reports.require_review_before_send' => true,
    ],
];