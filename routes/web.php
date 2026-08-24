<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Livewire\Attendance\Register;
use App\Livewire\Gradebook\Grid;
use App\Livewire\Today;
use Illuminate\Support\Facades\Route;


Route::get('/', function () {
    return view('welcome');
});
Route::get('/up', [HealthController::class, 'live'])->name('health.live');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::middleware('guest')->group(function (): void {
    Route::get('/sign-in', [SessionController::class, 'show'])->name('sign-in');
    Route::post('/sign-in', [SessionController::class, 'store'])->name('sign-in.store');
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::post('/sign-out', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/', Today::class)->name('dashboard');
    Route::get('/sessions/{session}/register', Register::class)->name('attendance.register');
    Route::get('/batches/{batch}/gradebook', Grid::class)->name('gradebook');

    // Placeholders until the Filament panel lands, so the navigation is honest
    // about what exists rather than linking to nothing.
    Route::view('/learners', 'placeholder', ['what' => 'Learners'])->name('learners');
    Route::view('/billing', 'placeholder', ['what' => 'Billing'])->name('billing');
});