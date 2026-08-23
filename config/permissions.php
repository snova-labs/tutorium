<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Permission catalogue
|--------------------------------------------------------------------------
|
| The single source of truth for every permission string in the product.
| Nothing in the codebase may branch on a user "type" — it asks whether the
| user holds one of these (SL-SRS-001 FR-IAM-2, SL-SEC-004 §5.1).
|
| Adding a permission here and re-running the seeder is the whole process.
| The labels are what a tenant owner reads when composing a role, so they are
| written from their side of the screen, not ours.
|
*/

return [

    'catalogue' => [

        'People' => [
            'learners.view' => 'View learners',
            'learners.create' => 'Add learners',
            'learners.update' => 'Edit learners',
            'learners.archive' => 'Archive learners',
            'learners.delete' => 'Delete learners permanently',
            'guardians.manage' => 'Manage guardians',
            'enrollments.manage' => 'Enroll and transfer learners',
        ],

        'Academic' => [
            'courses.manage' => 'Manage courses',
            'batches.manage' => 'Manage batches',
            'sessions.manage' => 'Schedule and cancel sessions',
        ],

        'Teaching' => [
            'attendance.view' => 'View attendance',
            'attendance.record' => 'Record attendance',
            'attendance.amend' => 'Amend attendance after a period closes',
            'assessments.manage' => 'Create and edit assessments',
            'grades.view' => 'View grades',
            'grades.enter' => 'Enter grades',
            'grades.amend' => 'Amend grades after a period closes',
            'notes.view' => 'Read notes',
            'notes.write' => 'Write notes',
            'notes.view_internal' => 'Read internal notes',
        ],

        'Reporting' => [
            'reports.generate' => 'Generate reports',
            'reports.send' => 'Send reports to recipients',
            'reports.view_archive' => 'Open the report archive',
            'dashboard.branch' => 'See branch analytics',
            'dashboard.tenant' => 'See account-wide analytics',
        ],

        'Administration' => [
            'organisation.manage' => 'Manage brands and branches',
            'settings.manage' => 'Change settings',
            'users.manage' => 'Manage people and roles',
            'audit.view' => 'Read the activity log',
            'data.export' => 'Export data',
            'billing.manage' => 'Manage billing',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded roles
    |--------------------------------------------------------------------------
    |
    | Examples, not fixed types (FR-IAM-4). A tenant owner may edit, rename or
    | delete any of them, and compose their own. "Owner" is the exception: it
    | passes every check through a Gate rule rather than a stored list, so a
    | newly added permission never locks an owner out of their own account.
    |
    */

    'owner_role' => 'Owner',

    'roles' => [

        'Management' => [
            'learners.view', 'learners.create', 'learners.update', 'learners.archive',
            'guardians.manage', 'enrollments.manage',
            'courses.manage', 'batches.manage', 'sessions.manage',
            'attendance.view', 'attendance.record', 'attendance.amend',
            'assessments.manage', 'grades.view', 'grades.enter', 'grades.amend',
            'notes.view', 'notes.write', 'notes.view_internal',
            'reports.generate', 'reports.send', 'reports.view_archive',
            'dashboard.branch', 'dashboard.tenant',
            'organisation.manage', 'settings.manage', 'users.manage',
            'audit.view', 'data.export',
        ],

        'Front desk' => [
            'learners.view', 'learners.create', 'learners.update',
            'guardians.manage', 'enrollments.manage',
            'attendance.view',
            'reports.generate', 'reports.view_archive',
            'data.export',
        ],

        'Teacher' => [
            'learners.view',
            'attendance.view', 'attendance.record',
            'assessments.manage', 'grades.view', 'grades.enter',
            'notes.view', 'notes.write', 'notes.view_internal',
            'reports.generate', 'reports.view_archive',
            'dashboard.branch',
        ],

        'Accountant' => [
            'learners.view',
            'dashboard.tenant',
            'billing.manage',
            'data.export',
        ],
    ],
];
