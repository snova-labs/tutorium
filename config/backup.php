<?php

declare(strict_types=1);

return [

    /*
    | Where backups are written: one folder per run, holding the database dump, the uploaded files
    | and a manifest of what both contain. In the Portainer stack this is its own volume, which the
    | server's nightly backup script copies off the machine.
    */
    'path' => env('BACKUP_PATH', storage_path('backups')),

    /* The files backed up alongside the database: uploads, generated reports, exports. */
    'files_path' => env('BACKUP_FILES_PATH', storage_path('app')),

    /* How many nightly backups to keep. Older folders are deleted after each successful run. */
    'keep' => env('BACKUP_KEEP', 14),

    /*
    | The restore drill (SL-415) restores the latest backup into this database, checks it against
    | the manifest, then empties it again. It must never be the live database: the drill drops
    | every table in it first, and refuses to run when the names match.
    */
    'drill_database' => env('BACKUP_DRILL_DATABASE'),

    /*
    | Tables whose rows are not worth restoring (caches, sessions, queued jobs that would run a
    | second time). Their structure is still backed up, so a restore produces a complete schema.
    */
    'structure_only' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'],

    /* Who hears about a failed backup or drill. Empty: every active operator. */
    'alert_to' => env('BACKUP_ALERT_TO'),

    /*
    | When `platform:backups` (and the health check behind it) calls the backups stale: a missed
    | night, or a missed weekly drill.
    */
    'max_age_hours' => [
        'backup' => 26,
        'drill' => 8 * 24,
    ],
];
