<?php

use App\Enums\VatRate;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'dashboard' : 'login');
});

// Placeholder only — the authentication flow is built in Phase 1.
Route::get('/login', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// Living styleguide — the D1–D3 design-system review page. Never in production.
if (! app()->isProduction()) {
    Route::get('/styleguide', function () {
        return Inertia::render('Styleguide', [
            'vatOptions' => VatRate::options(),
        ]);
    })->name('styleguide');
}

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});
