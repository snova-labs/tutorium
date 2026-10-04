<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\GradeBookController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LearnerController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\TerminologyController;
use App\Http\Controllers\Api\V1\UsageController;
use App\Http\Controllers\Public\InvitationController as PublicInvitationController;
use App\Http\Controllers\Public\SignupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioned from the first endpoint, because the future guardian portal,
| learner portal and any customer integration consume exactly these routes
| (SL-ARC-002 §1 "API-first").
|
| Middleware order matters: authentication must resolve the user before
| tenant.resolve can bind their tenant, and every write route sits behind
| the tenant guard that enforces read-only states.
|
*/

Route::prefix('v1')->group(function (): void {

    Route::post('auth/login', [AuthController::class, 'login'])->name('api.auth.login');
    Route::get('ping', fn () => response()->json(['data' => ['status' => 'ok']]))->name('api.ping');

    Route::middleware('throttle:20,1')->group(function (): void {
        Route::get('signup/options', [SignupController::class, 'options'])->name('public.signup.options');
        Route::post('signup', [SignupController::class, 'store'])->name('public.signup.store');
        Route::get('signup/verify/{token}', [SignupController::class, 'verify'])->name('public.signup.verify');

        Route::get('invitations/{token}', [PublicInvitationController::class, 'show'])
            ->name('public.invitations.show');
        Route::post('invitations/{token}', [PublicInvitationController::class, 'accept'])
            ->name('public.invitations.accept');
    });

    // Billing. Its own group so that an account made read-only for non-payment can still see what
    // it owes and fix how it pays. Support access never reaches these (RestrictImpersonatedAccess).
    Route::middleware(['auth:sanctum', 'tenant.resolve', 'tenant:billing'])->group(function (): void {
        Route::get('billing', [BillingController::class, 'show'])->name('api.billing.show');
        Route::put('billing/details', [BillingController::class, 'updateDetails'])->name('api.billing.details');
        Route::post('billing/payment-method', [BillingController::class, 'paymentMethodLink'])
            ->middleware('throttle:10,1')->name('api.billing.payment-method');
        Route::put('billing/collection', [BillingController::class, 'setCollection'])
            ->name('api.billing.collection');
        Route::post('billing/convert', [BillingController::class, 'convert'])
            ->middleware('throttle:10,1')->name('api.billing.convert');
    });

    Route::middleware(['auth:sanctum', 'tenant.resolve', 'tenant'])->group(function (): void {

        Route::post('billing/plan', [BillingController::class, 'changePlan'])->name('api.billing.plan');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('api.billing.cancel');

        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('api.me');

        // Organisation
        Route::apiResource('brands', BrandController::class)->names('api.brands');
        Route::apiResource('branches', BranchController::class)->names('api.branches');

        // Academic structure
        Route::apiResource('courses', CourseController::class)->names('api.courses');
        Route::apiResource('batches', BatchController::class)
            ->only(['index', 'store', 'show', 'update'])->names('api.batches');
        Route::post('batches/{batch}/timetable', [BatchController::class, 'addSlot'])
            ->name('api.batches.timetable.store');
        Route::put('batches/{batch}/teachers', [BatchController::class, 'assignTeachers'])
            ->name('api.batches.teachers');

        // Scheduling
        Route::get('batches/{batch}/sessions', [ScheduleController::class, 'sessions'])->name('api.batches.sessions');
        Route::post('batches/{batch}/sessions/generate', [ScheduleController::class, 'generate'])
            ->name('api.batches.sessions.generate');
        Route::get('batches/{batch}/periods', [ScheduleController::class, 'periods'])->name('api.batches.periods');
        Route::post('sessions/{session}/cancel', [ScheduleController::class, 'cancel'])->name('api.sessions.cancel');
        Route::post('sessions/{session}/reschedule', [ScheduleController::class, 'reschedule'])
            ->name('api.sessions.reschedule');

        // People
        Route::apiResource('learners', LearnerController::class)->names('api.learners');
        Route::get('guardians', [GuardianController::class, 'index'])->name('api.guardians.index');
        Route::post('learners/{learner}/guardians', [GuardianController::class, 'attach'])
            ->name('api.learners.guardians.attach');
        Route::delete('learners/{learner}/guardians/{guardian}', [GuardianController::class, 'detach'])
            ->name('api.learners.guardians.detach');
        Route::put('learners/{learner}/guardians/{guardian}/recipient', [GuardianController::class, 'setRecipient'])
            ->name('api.learners.guardians.recipient');

        // Enrollment. No destroy route by design — an enrollment is withdrawn, never deleted,
        // because the record that someone attended for six weeks is what the reports were built on.
        Route::apiResource('enrollments', EnrollmentController::class)
            ->only(['index', 'store', 'show'])->names('api.enrollments');
        Route::put('enrollments/{enrollment}/status', [EnrollmentController::class, 'changeStatus'])
            ->name('api.enrollments.status');
        Route::post('enrollments/{enrollment}/transfer', [EnrollmentController::class, 'transfer'])
            ->name('api.enrollments.transfer');

        Route::get('sessions/{session}/attendance', [AttendanceController::class, 'roster'])
            ->name('api.sessions.attendance.roster');
        Route::put('sessions/{session}/attendance', [AttendanceController::class, 'save'])
            ->name('api.sessions.attendance.save');
        Route::post('sessions/{session}/attendance/remaining', [AttendanceController::class, 'markRemaining'])
            ->name('api.sessions.attendance.remaining');

        Route::get('batches/{batch}/attendance', [AttendanceController::class, 'summary'])
            ->name('api.batches.attendance.summary');
        Route::get('enrollments/{enrollment}/attendance', [AttendanceController::class, 'forEnrollment'])
            ->name('api.enrollments.attendance');
        Route::get('grading/vocabularies', [GradeBookController::class, 'vocabularies'])
            ->name('api.grading.vocabularies');

        Route::get('batches/{batch}/gradebook', [GradeBookController::class, 'grid'])
            ->name('api.batches.gradebook');
        Route::put('batches/{batch}/gradebook', [GradeBookController::class, 'save'])
            ->name('api.batches.gradebook.save');
        Route::post('batches/{batch}/assessments', [GradeBookController::class, 'storeAssessment'])
            ->name('api.batches.assessments.store');

        Route::get('assessments/{assessment}/ungraded', [GradeBookController::class, 'ungraded'])
            ->name('api.assessments.ungraded');
        Route::get('enrollments/{enrollment}/average', [GradeBookController::class, 'average'])
            ->name('api.enrollments.average');
        // Notes
        Route::get('enrollments/{enrollment}/notes', [NoteController::class, 'index'])->name('api.notes.index');
        Route::post('enrollments/{enrollment}/notes', [NoteController::class, 'store'])->name('api.notes.store');
        Route::post('batches/{batch}/notes', [NoteController::class, 'storeMany'])->name('api.notes.bulk');

        // Reporting
        Route::get('batches/{batch}/reports/readiness', [ReportController::class, 'readiness'])
            ->name('api.reports.readiness');
        Route::post('batches/{batch}/reports', [ReportController::class, 'generateBatch'])
            ->name('api.reports.generate');
        Route::get('report-runs/{run}', [ReportController::class, 'run'])->name('api.reports.run');

        Route::get('reports', [ReportController::class, 'index'])->name('api.reports.index');
        Route::get('reports/{report}/download', [ReportController::class, 'download'])->name('api.reports.download');
        Route::post('reports/{report}/send', [ReportController::class, 'send'])->name('api.reports.send');
        Route::post('report-deliveries/{delivery}/retry', [ReportController::class, 'retryDelivery'])
            ->name('api.reports.retry');

        Route::get('report-templates/{template}/preview', [ReportController::class, 'preview'])
            ->name('api.reports.preview');

        Route::get('onboarding', [OnboardingController::class, 'status'])->name('api.onboarding.status');
        Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss'])->name('api.onboarding.dismiss');

        Route::get('presets', [OnboardingController::class, 'presets'])->name('api.presets.index');
        Route::post('presets/apply', [OnboardingController::class, 'applyPreset'])->name('api.presets.apply');

        Route::post('onboarding/sample-data', [OnboardingController::class, 'loadSample'])
            ->name('api.onboarding.sample.load');
        Route::delete('onboarding/sample-data', [OnboardingController::class, 'removeSample'])
            ->name('api.onboarding.sample.remove');

        Route::get('terminology', [TerminologyController::class, 'index'])->name('api.terminology.index');
        Route::put('terminology', [TerminologyController::class, 'update'])->name('api.terminology.update');

        Route::get('usage', [UsageController::class, 'meter'])->name('api.usage.meter');
        Route::get('usage/invoices', [UsageController::class, 'invoices'])->name('api.usage.invoices');

        // Team. Inside the authenticated group like everything else: the controller asks the
        // signed-in user what they may grant, and the trial status belongs to their tenant.
        Route::get('invitations', [InvitationController::class, 'index'])->name('api.invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('api.invitations.store');
        Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])
            ->name('api.invitations.resend');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'revoke'])
            ->name('api.invitations.revoke');
        Route::get('trial', [InvitationController::class, 'trial'])->name('api.trial.status');
    });
});
