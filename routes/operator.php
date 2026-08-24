<?php

declare(strict_types=1);

use App\Http\Controllers\Operator\ImpersonationController;
use App\Http\Controllers\Operator\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Control plane
|--------------------------------------------------------------------------
|
| A separate route file behind a separate guard. Customers never reach these,
| and nothing here goes through tenant resolution — an operator is not a tenant
| user with extra permissions, they come through a different door entirely.
|
| Register in bootstrap/app.php:
|
|   ->withRouting(
|       ...
|       then: function () {
|           Route::middleware(['api', 'auth:operator', 'operator'])
//              Deliberately not under /api/v1 — this is not the product's API.
|               ->prefix('operator/v1')
|               ->group(base_path('routes/operator.php'));
|       },
|   )
|
*/

Route::get('tenants', [TenantController::class, 'index'])->name('operator.tenants.index');
Route::post('tenants', [TenantController::class, 'store'])->name('operator.tenants.store');
Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('operator.tenants.show');

Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('operator.tenants.suspend');
Route::post('tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('operator.tenants.reactivate');
Route::post('tenants/{tenant}/cancel', [TenantController::class, 'cancel'])->name('operator.tenants.cancel');
Route::post('tenants/{tenant}/purge', [TenantController::class, 'purge'])->name('operator.tenants.purge');
Route::post('tenants/{tenant}/export', [TenantController::class, 'export'])->name('operator.tenants.export');

Route::get('support-access', [ImpersonationController::class, 'index'])->name('operator.impersonation.index');
Route::post('tenants/{tenant}/support-access', [ImpersonationController::class, 'start'])
    ->name('operator.impersonation.start');
Route::post('support-access/{impersonation}/end', [ImpersonationController::class, 'end'])
    ->name('operator.impersonation.end');
