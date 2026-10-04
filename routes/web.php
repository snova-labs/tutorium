<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SessionController;
use App\Livewire\Attendance\Register;
use App\Livewire\Gradebook\Grid;
use App\Livewire\Today;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
|
| The teacher-facing interface. Session-authenticated, so ResolveTenant runs
| in the web group and the tenant is bound for the whole request.
|
| Note Route::livewire() rather than Route::get() for the three component
| routes: Livewire v4 requires it for full-page components. Under Route::get()
| they render but do not behave correctly, which is a failure mode that looks
| like a styling problem rather than a routing one.
|
| Administrative CRUD lives in the Filament panel at /admin. These three are
| what somebody touches every day.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/sign-in', [SessionController::class, 'show'])->name('sign-in');
    Route::post('/sign-in', [SessionController::class, 'store'])->name('sign-in.store');
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::post('/sign-out', [SessionController::class, 'destroy'])->name('logout');

    Route::livewire('/', Today::class)->name('dashboard');
    Route::livewire('/sessions/{session}/register', Register::class)->name('attendance.register');
    Route::livewire('/batches/{batch}/gradebook', Grid::class)->name('gradebook');

    // The panel owns these now. Kept as redirects rather than deleted, so any
    // link already in an email or a bookmark still lands somewhere sensible.
    Route::redirect('/learners', '/admin/learners')->name('learners');
    Route::redirect('/billing', '/admin')->name('billing');
});
