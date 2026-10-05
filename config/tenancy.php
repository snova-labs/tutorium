<?php

declare(strict_types=1);

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BillingProfile;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\DunningAttempt;
use App\Models\EmailTemplate;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusHistory;
use App\Models\Grade;
use App\Models\GradeRubricScore;
use App\Models\GradingScheme;
use App\Models\Guardian;
use App\Models\Holiday;
use App\Models\IdSequence;
use App\Models\Impersonation;
use App\Models\Import;
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\MakeupLink;
use App\Models\NoteCategory;
use App\Models\Operator;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\PlanFeature;
use App\Models\PresetApplication;
use App\Models\RelationType;
use App\Models\Report;
use App\Models\ReportDelivery;
use App\Models\ReportingPeriod;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Models\RubricCriterion;
use App\Models\SampleDataSet;
use App\Models\SessionType;
use App\Models\Setting;
use App\Models\SignInCode;
use App\Models\SignupAttempt;
use App\Models\SubmissionStatus;
use App\Models\Subscription;
use App\Models\TeacherNote;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\TenantExport;
use App\Models\TerminologyOverride;
use App\Models\TimetableSlot;
use App\Models\TypeWeight;
use App\Models\UsageSnapshot;
use App\Models\User;
use App\Models\WebhookEvent;

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
        Import::class,
        Guardian::class,
        Enrollment::class,
        EnrollmentStatusHistory::class,

        // Cross-cutting
        AuditLog::class,

        // Attendance
        AttendanceStatus::class,
        AttendancePolicy::class,
        AttendanceRecord::class,
        MakeupLink::class,

        AssessmentType::class,
        SubmissionStatus::class,
        GradingScheme::class,
        Assessment::class,
        RubricCriterion::class,
        Grade::class,
        GradeRubricScore::class,
        TypeWeight::class,

        NoteCategory::class,
        TeacherNote::class,
        ReportTemplate::class,
        Report::class,
        ReportRun::class,
        ReportDelivery::class,
        EmailTemplate::class,

        TerminologyOverride::class,
        PresetApplication::class,
        SampleDataSet::class,

        TenantExport::class,
        Subscription::class,
        UsageSnapshot::class,
        Invoice::class,
        TenantEntitlementOverride::class,
        Invitation::class,
        BillingProfile::class,
        DunningAttempt::class,
        InvoiceLine::class,
        PlanChange::class,
    ],

    'global_models' => [
        Tenant::class,
        Operator::class,
        Impersonation::class,
        Plan::class,
        PlanFeature::class,
        SignupAttempt::class,
        // Operators have no tenant, and at sign-in no tenant is bound yet. Always reached through
        // its subject (an operator or a user), never queried bare (SignInCodes).
        SignInCode::class,
        // Looked up by hash before anyone is known. Its owner then decides the tenant.
        PersonalAccessToken::class,
        // A provider's event arrives before we know whose it is. The tenant is read from its
        // payload and every change it causes is applied inside that tenant (WebhookProcessor).
        WebhookEvent::class,
    ],

];
