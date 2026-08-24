<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Preset catalogue
|--------------------------------------------------------------------------
|
| A preset is the answer to the objection that a fully configurable product is
| unusable on day one. It fills in the parts an academy of a given kind, in a
| given country, would set the same way — and then gets out of the way.
|
| Nothing here is a lock. Every value a preset writes is an ordinary tenant
| setting afterwards, editable in the interface and marked in the resolver as
| having come from a preset (SL-PRD-000 §7).
|
| `defaults` is merged into every preset, so a definition below only states
| where it differs. That keeps the differences readable, which is the point.
|
*/

$defaults = [
    'version' => 1,

    'terminology' => [
        'learner' => ['Learner', 'Learners'],
        'guardian' => ['Guardian', 'Guardians'],
        'batch' => ['Batch', 'Batches'],
        'course' => ['Course', 'Courses'],
        'session' => ['Session', 'Sessions'],
        'assessment' => ['Assessment', 'Assessments'],
        'period' => ['Period', 'Periods'],
    ],

    'modules' => [
        'people.guardians_enabled' => true,
        'grading.rubrics_enabled' => true,
        'scheduling.meeting_links_enabled' => false,
        'people.sponsors_enabled' => false,
    ],

    'settings' => [
        'attendance.compulsory' => true,
        'attendance.allow_late_join' => true,
        'attendance.late_grace_minutes' => 10,
        'attendance.low_threshold_pct' => 75,
        'periods.type' => 'monthly',
        'locale.calendar' => 'gregorian',
        'locale.week_start' => 'monday',
        'locale.weekend_days' => ['saturday', 'sunday'],
        'reports.require_review_before_send' => true,
    ],

    'sequences' => [
        'learner' => ['prefix' => 'STU', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        'enrollment' => ['prefix' => 'ENR', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        'assessment' => ['prefix' => 'ASM', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        'report' => ['prefix' => 'RPT', 'separator' => '-', 'pad_width' => 5, 'start' => 1],
    ],

    'session_types' => [
        ['Class', 'CLASS', true],
        ['Lab', 'LAB', true],
        ['Workshop', 'WORKSHOP', true],
        // Recorded, but outside the headline figure: a make-up class must not inflate a term.
        ['Make-up', 'MAKEUP', false],
    ],

    'attendance_statuses' => [
        // name, code, attended, in rate, late, negative
        ['Present', 'PRESENT', true, true, false, false],
        ['Late', 'LATE', true, true, true, false],
        ['Absent', 'ABSENT', false, true, false, true],
        // Neither attended nor negative — leaves the denominator rather than scoring zero.
        ['Excused', 'EXCUSED', false, false, false, false],
    ],

    'submission_statuses' => [
        // name, code, submitted, excluded from average, negative
        ['Submitted', 'SUBMITTED', true, false, false],
        ['Late', 'LATE_SUB', true, false, false],
        ['Missing', 'MISSING', false, false, true],
        ['Exempt', 'EXEMPT', false, true, false],
    ],

    'learner_statuses' => [
        ['Prospect', 'PROSPECT', false],
        ['Active', 'ACTIVE', false],
        ['On hold', 'ON_HOLD', false],
        ['Completed', 'COMPLETED', true],
        ['Withdrawn', 'WITHDRAWN', true],
    ],

    'enrollment_statuses' => [
        // name, code, counts toward the bill, terminal
        ['Active', 'ACTIVE', true, false],
        ['On hold', 'ON_HOLD', false, false],
        ['Completed', 'COMPLETED', false, true],
        ['Transferred', 'TRANSFERRED', false, true],
        ['Withdrawn', 'WITHDRAWN', false, true],
    ],

    'relation_types' => [
        ['Mother', 'MOTHER'], ['Father', 'FATHER'], ['Guardian', 'GUARDIAN'], ['Sponsor', 'SPONSOR'],
    ],

    'note_categories' => [
        // name, code, shown on reports by default
        ['General', 'GENERAL', true],
        ['Academic', 'ACADEMIC', true],
        ['Participation', 'PARTICIPATION', true],
        ['Attendance', 'ATTENDANCE', false],
        // Off by default: a behaviour note is context for colleagues first.
        ['Behaviour', 'BEHAVIOUR', false],
    ],

    'assessment_types' => [
        // name, code, counts in submission rate, weight (null = unweighted)
        ['Homework', 'HOMEWORK', true, 40],
        ['Classwork', 'CLASSWORK', true, null],
        ['Project', 'PROJECT', true, 40],
        ['Quiz', 'QUIZ', false, 20],
    ],

    'grading_schemes' => [
        ['Points', 'POINTS', 'points', []],
        ['Percentage', 'PERCENT', 'percentage', []],
        ['Pass / fail', 'PASSFAIL', 'pass_fail', ['pass_value' => 100, 'fail_value' => 0]],
        ['Rubric', 'RUBRIC', 'rubric', []],
    ],

    'report' => [
        'blocks' => ['summary', 'engagement', 'highlights', 'work', 'notes'],
        'closing' => 'Warm regards, the teaching team',
    ],

    'email_templates' => [
        'report_delivery' => [
            'subject' => '{brand_name} — progress report for {period}',
            'body' => '<p>Dear {recipient_name},</p><p>The progress report for <strong>{learner_name}</strong> '
                .'in {batch_name}, covering {period}, is attached.</p><p>If anything in it raises a question, '
                .'reply to this message and it will reach us directly.</p><p>{brand_name}</p>',
        ],
    ],
];

$presets = [

    'kids-tutoring-south-asia' => [
        'name' => 'Kids tutoring centre — South Asia',
        'vertical' => 'tutoring',
        'summary' => 'Guardians on, monthly reports, points grading, Sunday week start, '
            .'Bikram Sambat available as a second calendar.',
        'terminology' => [
            'learner' => ['Student', 'Students'],
            'guardian' => ['Parent', 'Parents'],
            'batch' => ['Class', 'Classes'],
        ],
        'settings' => [
            'locale.week_start' => 'sunday',
            'locale.weekend_days' => ['saturday'],
            'locale.secondary_calendar' => 'bikram_sambat',
        ],
        'sequences' => [
            'learner' => ['prefix' => 'STU', 'separator' => '-', 'pad_width' => 4, 'start' => 1],
        ],
    ],

    'after-school-north-america' => [
        'name' => 'After-school programme — North America',
        'vertical' => 'tutoring',
        'summary' => 'Guardians on, monthly reports, letter grades, Sunday week start, '
            .'conservative retention defaults for under-13s.',
        'terminology' => [
            'learner' => ['Student', 'Students'],
            'guardian' => ['Parent', 'Parents'],
            'batch' => ['Class', 'Classes'],
        ],
        'settings' => [
            'locale.week_start' => 'sunday',
            // Children's data in the US carries its own expectations; the preset starts strict
            // and lets an academy loosen it deliberately rather than the reverse.
            'privacy.retain_withdrawn_years' => 3,
            'privacy.minimise_optional_fields' => true,
        ],
        'grading_schemes' => [
            ['Points', 'POINTS', 'points', []],
            ['Letter A–F', 'LETTER_AF', 'letter', ['bands' => [
                ['label' => 'A', 'min' => 90, 'max' => 100],
                ['label' => 'B', 'min' => 80, 'max' => 89],
                ['label' => 'C', 'min' => 70, 'max' => 79],
                ['label' => 'D', 'min' => 60, 'max' => 69],
                ['label' => 'F', 'min' => 0, 'max' => 59],
            ]]],
            ['Pass / fail', 'PASSFAIL', 'pass_fail', ['pass_value' => 100, 'fail_value' => 0]],
            ['Rubric', 'RUBRIC', 'rubric', []],
        ],
    ],

    'language-school-europe' => [
        'name' => 'Language school — Europe',
        'vertical' => 'language',
        'summary' => 'Guardians off by default, term reports, CEFR levels, Monday week start, '
            .'EU retention defaults.',
        'modules' => [
            // Adult learners receive their own reports; showing a guardian field would be noise.
            'people.guardians_enabled' => false,
        ],
        'settings' => [
            'periods.type' => 'term',
            'attendance.allow_late_join' => true,
            'privacy.retain_withdrawn_years' => 2,
        ],
        'terminology' => [
            'learner' => ['Learner', 'Learners'],
            'batch' => ['Group', 'Groups'],
        ],
        'assessment_types' => [
            ['Speaking', 'SPEAKING', true, 30],
            ['Writing', 'WRITING', true, 30],
            ['Listening', 'LISTENING', true, 20],
            ['Reading', 'READING', true, 20],
        ],
        'grading_schemes' => [
            ['CEFR', 'CEFR', 'level', ['ladder' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']]],
            ['Points', 'POINTS', 'points', []],
            ['Rubric', 'RUBRIC', 'rubric', []],
            ['Pass / fail', 'PASSFAIL', 'pass_fail', ['pass_value' => 100, 'fail_value' => 0]],
        ],
        'report' => [
            'blocks' => ['summary', 'highlights', 'work', 'notes'],
            'closing' => 'Kind regards, the teaching team',
        ],
    ],

    'language-school-gulf' => [
        'name' => 'Language school — Gulf',
        'vertical' => 'language',
        'summary' => 'Friday–Saturday weekend, Hijri available as a second calendar, term reports.',
        'modules' => ['people.guardians_enabled' => false],
        'settings' => [
            'periods.type' => 'term',
            'locale.week_start' => 'sunday',
            // The assumption that a weekend is Saturday and Sunday is the single most common way
            // school software fails outside its home country.
            'locale.weekend_days' => ['friday', 'saturday'],
            'locale.secondary_calendar' => 'hijri',
        ],
        'grading_schemes' => [
            ['CEFR', 'CEFR', 'level', ['ladder' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']]],
            ['Points', 'POINTS', 'points', []],
            ['Rubric', 'RUBRIC', 'rubric', []],
        ],
    ],

    'skills-institute' => [
        'name' => 'IT & skills institute',
        'vertical' => 'skills',
        'summary' => 'Guardians off, cohort-block reports, project and lab assessment types, '
            .'employer as an alternative report recipient.',
        'modules' => [
            'people.guardians_enabled' => false,
            'people.sponsors_enabled' => true,
            'scheduling.meeting_links_enabled' => true,
        ],
        'settings' => [
            'periods.type' => 'block',
            'periods.block_weeks' => 4,
            'attendance.low_threshold_pct' => 80,
        ],
        'terminology' => [
            'learner' => ['Trainee', 'Trainees'],
            'batch' => ['Cohort', 'Cohorts'],
            'course' => ['Programme', 'Programmes'],
        ],
        'assessment_types' => [
            ['Lab', 'LAB', true, 30],
            ['Project', 'PROJECT', true, 50],
            ['Quiz', 'QUIZ', true, 20],
        ],
        'report' => [
            'blocks' => ['summary', 'work', 'notes'],
            'closing' => 'Congratulations on the progress made this block.',
        ],
    ],

    'corporate-training' => [
        'name' => 'Corporate training provider',
        'vertical' => 'skills',
        'summary' => 'Participants rather than learners, completion-focused reporting, '
            .'sponsor records, compulsory attendance.',
        'modules' => [
            'people.guardians_enabled' => false,
            'people.sponsors_enabled' => true,
            'scheduling.meeting_links_enabled' => true,
        ],
        'terminology' => [
            'learner' => ['Participant', 'Participants'],
            'batch' => ['Cohort', 'Cohorts'],
            'course' => ['Programme', 'Programmes'],
            'assessment' => ['Activity', 'Activities'],
        ],
        'settings' => [
            'periods.type' => 'block',
            'attendance.compulsory' => true,
            'attendance.allow_late_join' => false,
            'attendance.low_threshold_pct' => 90,
        ],
        'assessment_types' => [
            ['Exercise', 'EXERCISE', true, 40],
            ['Assessment', 'ASSESSMENT', true, 60],
        ],
        'grading_schemes' => [
            ['Pass / fail', 'PASSFAIL', 'pass_fail', ['pass_value' => 100, 'fail_value' => 0]],
            ['Points', 'POINTS', 'points', []],
            ['Rubric', 'RUBRIC', 'rubric', []],
        ],
        'report' => ['blocks' => ['summary', 'work'], 'closing' => 'Thank you for taking part.'],
    ],

    'blank' => [
        'name' => 'Start from blank',
        'vertical' => 'any',
        'summary' => 'Minimal defaults. Choose this only if none of the others is close — '
            .'expect a longer setup.',
        'session_types' => [['Class', 'CLASS', true]],
        'assessment_types' => [['Assessment', 'ASSESSMENT', true, null]],
        'grading_schemes' => [['Points', 'POINTS', 'points', []]],
        'note_categories' => [['General', 'GENERAL', true]],
        'report' => ['blocks' => ['summary', 'work'], 'closing' => null],
    ],
];

return ['defaults' => $defaults, 'catalogue' => $presets];
