<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\LearnerController;
use App\Http\Controllers\Api\V1\ScheduleController;
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

    Route::middleware(['auth:sanctum', 'tenant.resolve', 'tenant'])->group(function (): void {

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
    });
});
