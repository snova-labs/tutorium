<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioned from the first endpoint, because the future guardian portal,
| learner portal and any customer integration consume exactly these routes
| (SL-ARC-002 §1 "API-first"). Breaking changes get a new version; additive
| changes ship within one.
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
        Route::get('batches/{batch}/sessions', [ScheduleController::class, 'sessions'])
            ->name('api.batches.sessions');
        Route::post('batches/{batch}/sessions/generate', [ScheduleController::class, 'generate'])
            ->name('api.batches.sessions.generate');
        Route::get('batches/{batch}/periods', [ScheduleController::class, 'periods'])
            ->name('api.batches.periods');

        // Sessions are cancelled or rescheduled, never deleted — the record of what was planned
        // is part of the history (SL-DAT-003 §13).
        Route::post('sessions/{session}/cancel', [ScheduleController::class, 'cancel'])
            ->name('api.sessions.cancel');
        Route::post('sessions/{session}/reschedule', [ScheduleController::class, 'reschedule'])
            ->name('api.sessions.reschedule');
    });
});
