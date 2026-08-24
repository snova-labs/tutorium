<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusHistory;
use App\Models\Guardian;
use App\Models\Holiday;
use App\Models\IdSequence;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\RelationType;
use App\Models\ReportingPeriod;
use App\Models\SessionType;
use App\Models\Setting;
use App\Models\TimetableSlot;
use App\Models\User;

return [

    /*
    |---------------------------------------------------------------------------
    | Tenant resource registry
    |---------------------------------------------------------------------------
    |
    | Every tenant-owned model MUST be listed here. TenantRegistryTest scans
    | app/Models, finds every model using BelongsToTenant, and fails the build if
    | one is missing — so adding a resource without isolation coverage is not an
    | oversight that can reach production, it is a red pipeline.
    |
    */

    'resources' => [
        // Organisation
        Brand::class,
        Branch::class,
        Holiday::class,

        // Identity
        User::class,

        // Configuration and vocabularies
        Setting::class,
        IdSequence::class,
        SessionType::class,
        LearnerStatus::class,
        EnrollmentStatus::class,
        RelationType::class,

        // Academic
        Course::class,
        Batch::class,
        TimetableSlot::class,
        ClassSession::class,
        ReportingPeriod::class,

        // People
        Learner::class,
        Guardian::class,
        Enrollment::class,
        EnrollmentStatusHistory::class,

        // Cross-cutting
        AuditLog::class,
    ],

    'global_models' => [
        \App\Models\Tenant::class,
    ],

];