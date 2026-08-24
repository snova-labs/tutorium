<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
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

        Route::apiResource('brands', BrandController::class)->names('api.brands');
        Route::apiResource('branches', BranchController::class)->names('api.branches');
    });
});
