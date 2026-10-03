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
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\MakeupLink;
use App\Models\NoteCategory;
use App\Models\Operator;
use App\Models\PaymentEvent;
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

/*
|--------------------------------------------------------------------------
| Tenant resource registry — canonical
|--------------------------------------------------------------------------
|
| Every tenant-owned model MUST be listed under `resources`, and every model
| that is not tenant-owned MUST be listed under `global_models`. Nothing may
| be in neither: `platform:verify` and TenantRegistryTest both fail the build
| for an unclassified model, because an unclassified model is one whose
| isolation nobody decided.
|
| This file replaces every earlier version. Grouped by the layer that
| introduced each model, so a new one is easy to place.
|
*/

return [

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
        
        AttendanceStatus::class,
        SubmissionStatus::class,
        AssessmentType::class,
        GradingScheme::class,
        NoteCategory::class,

        // Academic structure
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

        // Attendance
        AttendancePolicy::class,
        AttendanceRecord::class,
        MakeupLink::class,

        // Grading
        Assessment::class,
        RubricCriterion::class,
        Grade::class,
        GradeRubricScore::class,
        TypeWeight::class,

        // Notes and reporting
        TeacherNote::class,
        ReportTemplate::class,
        Report::class,
        ReportRun::class,
        ReportDelivery::class,
        EmailTemplate::class,

        // Onboarding
        TerminologyOverride::class,
        PresetApplication::class,
        SampleDataSet::class,
        TenantExport::class,

        // Billing
        Subscription::class,
        UsageSnapshot::class,
        Invoice::class,
        InvoiceLine::class,
        TenantEntitlementOverride::class,
        BillingProfile::class,
        DunningAttempt::class,
        Invitation::class,
        PlanChange::class,

        // Cross-cutting
        AuditLog::class,
    ],

    /*
    |---------------------------------------------------------------------------
    | Models exempt from tenant ownership
    |---------------------------------------------------------------------------
    |
    | Control-plane and platform-wide records. Adding one here is a deliberate
    | statement that it holds no customer data, and should be reviewed like a
    | security change — because a mistake here is a leak rather than a bug.
    |
    */

    'global_models' => [
        Tenant::class,
        Operator::class,
        Impersonation::class,
        Plan::class,
        PlanFeature::class,
        WebhookEvent::class,
        SignupAttempt::class,
        PaymentEvent::class,
    ],

];