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

        // Security
        // Roles that must enter a code emailed to them at every sign-in. The ones that can see
        // money or change who has access; an academy can widen it to everyone.
        'security.sign_in_code_roles' => ['Owner', 'Management', 'Accountant'],
    ],

    /*
    |--------------------------------------------------------------------------
    | How each setting reads on a screen
    |--------------------------------------------------------------------------
    |
    | The resolver answers what a value is and where it came from; this says
    | what the value MEANS, so one screen can offer all of them without
    | hardcoding a control per key. A key absent from here is deliberately not
    | offered: locale.week_start and locale.weekend_days belong to a location,
    | where a weekend is actually decided, and appear on the location screens.
    |
    | type: switch | number | choice | roles
    |
    */

    'screen' => [
        'people' => [
            'name' => 'People',
            'keys' => [
                'people.guardians_enabled' => [
                    'type' => 'switch',
                    'label' => 'Learners have guardians',
                    'help' => 'Off for an academy whose learners are all adults: guardians stop being asked for anywhere.',
                ],
                'people.require_guardian_for_minors' => [
                    'type' => 'switch',
                    'label' => 'A minor must have a guardian',
                    'help' => 'Enrolling someone under 18 without one is refused.',
                ],
                'people.duplicate_check_enabled' => [
                    'type' => 'switch',
                    'label' => 'Warn about likely duplicates',
                    'help' => 'When a new learner looks like one you already have. It warns; it never blocks.',
                ],
            ],
        ],

        'attendance' => [
            'name' => 'Attendance',
            'keys' => [
                'attendance.compulsory' => [
                    'type' => 'switch',
                    'label' => 'Registers must be taken',
                    'help' => 'A session cannot be closed with its register untouched.',
                ],
                'attendance.allow_late_join' => [
                    'type' => 'switch',
                    'label' => 'Someone can join a class part-way through the period',
                    'help' => 'Their attendance is counted from the day they joined, not from the start.',
                ],
                'attendance.late_grace_minutes' => [
                    'type' => 'number',
                    'label' => 'Minutes before an arrival counts as late',
                    'help' => 'Within this many minutes of the start, a learner is simply present.',
                    'min' => 0,
                    'max' => 120,
                ],
                'attendance.low_threshold_pct' => [
                    'type' => 'number',
                    'label' => 'Attendance below this needs attention',
                    'help' => 'The figure a report and the batch list mark as low, as a percentage.',
                    'min' => 0,
                    'max' => 100,
                ],
            ],
        ],

        'grading' => [
            'name' => 'Grading',
            'keys' => [
                'grading.default_scheme' => [
                    'type' => 'choice',
                    'label' => 'How work is marked by default',
                    'help' => 'A course or an assessment can still use its own scheme.',
                    'choices' => [
                        'points' => 'Points out of a maximum',
                        'percentage' => 'Percentage',
                        'pass_fail' => 'Pass or fail',
                        'letter' => 'Letter grades',
                        'level' => 'Levels',
                    ],
                ],
                'grading.weighted_averages' => [
                    'type' => 'switch',
                    'label' => 'Weight an average by assessment type',
                    'help' => 'Off: every piece of work counts the same toward an average.',
                ],
            ],
        ],

        'periods' => [
            'name' => 'Periods',
            'keys' => [
                'periods.type' => [
                    'type' => 'choice',
                    'label' => 'What a reporting period is',
                    'help' => 'The span a report and an average cover. Changing it does not alter periods already closed.',
                    'choices' => [
                        'monthly' => 'A calendar month',
                        'termly' => 'A term you define',
                        'quarterly' => 'A quarter',
                        'custom' => 'Dates you set each time',
                    ],
                ],
            ],
        ],

        'reports' => [
            'name' => 'Reports',
            'keys' => [
                'reports.include_notes' => [
                    'type' => 'switch',
                    'label' => "Teachers' notes can appear on reports",
                    'help' => 'Off: a note is never shown to a guardian, whatever a template says.',
                ],
                'reports.require_review_before_send' => [
                    'type' => 'switch',
                    'label' => 'A report is reviewed before it is sent',
                    'help' => 'Somebody who can generate reports is not enough; sending needs a second pair of eyes.',
                ],
            ],
        ],

        'locale' => [
            'name' => 'Dates',
            'keys' => [
                'locale.calendar' => [
                    'type' => 'choice',
                    'label' => 'Calendar',
                    'help' => 'The calendar dates are shown in. Stored dates never change.',
                    'choices' => ['gregorian' => 'Gregorian', 'bikram_sambat' => 'Bikram Sambat', 'hijri' => 'Hijri'],
                ],
            ],
        ],

        'security' => [
            'name' => 'Signing in',
            'keys' => [
                'security.sign_in_code_roles' => [
                    'type' => 'roles',
                    'label' => 'Roles that confirm each sign-in with an emailed code',
                    'help' => 'Narrowing this makes signing in easier and the account easier to break into.',
                ],
            ],
        ],
    ],
];
