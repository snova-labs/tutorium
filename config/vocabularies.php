<?php

declare(strict_types=1);

use App\Models\ClassSession;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\NoteCategory;
use App\Models\SessionType;
use App\Models\TeacherNote;
use App\Models\TimetableSlot;

return [

    /*
    |--------------------------------------------------------------------------
    | The academy's own small lists
    |--------------------------------------------------------------------------
    |
    | A preset fills these in on day one, and then an academy needs its own: a
    | "Trial lesson" session type, a "Behaviour" note category, an "On a break"
    | learner status. Before this registry those rows existed and nothing in the
    | interface could add one.
    |
    | Each list declares the one flag that changes how the product behaves, so
    | the screen can say what ticking it does rather than showing a bare field
    | name. `uses` is how a term is checked for being in use: a term nothing has
    | used can be removed, and a term with history never is, because the
    | attendance registers, notes and learner records that point at it would
    | lose their meaning.
    |
    | Enrollment and attendance statuses are deliberately absent. Their flags
    | decide what is invoiced and what counts toward an attendance percentage,
    | so they belong with the billing and attendance screens, where the
    | consequence of a change can be shown next to the change.
    |
    */

    'lists' => [

        'session-types' => [
            'model' => SessionType::class,
            'name' => 'Session types',
            'singular' => 'session type',
            'about' => 'What kind of class a session is: a normal lesson, a make-up, a trial.',
            'flag' => [
                'field' => 'counts_in_attendance',
                'label' => 'Counts toward attendance',
                'off' => 'Does not count toward attendance',
                'help' => 'Off for a make-up or a trial, so it is recorded without moving the term\'s attendance figure.',
                'default' => true,
            ],
            // Switched off rather than removed once sessions have used it.
            'deactivates' => true,
            'uses' => [ClassSession::class => 'session_type_id', TimetableSlot::class => 'session_type_id'],
        ],

        'note-categories' => [
            'model' => NoteCategory::class,
            'name' => 'Note categories',
            'singular' => 'note category',
            'about' => 'How a teacher files a note: progress, behaviour, a word about homework.',
            'flag' => [
                'field' => 'report_visible_default',
                'label' => 'Goes on reports by default',
                'off' => 'Not on reports by default',
                'help' => 'A note in this category starts out visible to guardians. The teacher can still change it on the note.',
                'default' => false,
            ],
            'deactivates' => false,
            'uses' => [TeacherNote::class => 'note_category_id'],
        ],

        'learner-statuses' => [
            'model' => LearnerStatus::class,
            'name' => 'Learner statuses',
            'singular' => 'learner status',
            'about' => 'Where a learner stands with the academy, apart from any one enrolment.',
            'flag' => [
                'field' => 'is_terminal',
                'label' => 'Has left',
                'off' => 'Still with you',
                'help' => 'A status that means the learner is no longer with you. Used to keep them out of the lists you work from.',
                'default' => false,
            ],
            'deactivates' => false,
            'uses' => [Learner::class => 'status_id'],
        ],

    ],
];
