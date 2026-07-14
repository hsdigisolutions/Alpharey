<?php

use App\Enums\VatRate;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PermissionMatrixController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ColumnSettingsController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeCallLogController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportExportController;
use App\Http\Controllers\EmployeeNoteController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    $user = Auth::user();

    if ($user === null) {
        return redirect()->route('login');
    }

    return redirect()->route($user->isSuperAdmin() ? 'welcome' : 'dashboard');
});

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

/*
|--------------------------------------------------------------------------
| Guests — Screen 01 (login) + password reset
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')->name('password.store');
});

// Living styleguide — the D1–D3 design-system review page. Never in production.
if (! app()->isProduction()) {
    Route::get('/styleguide', function () {
        return Inertia::render('Styleguide', [
            'vatOptions' => VatRate::options(),
        ]);
    })->name('styleguide');
}

/*
|--------------------------------------------------------------------------
| Authenticated (active accounts only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // Screen 05/06 — Employees (module permissions checked in controllers)
    Route::get('/employees/export', [EmployeeImportExportController::class, 'export'])->name('employees.export');
    Route::get('/employees/template', [EmployeeImportExportController::class, 'template'])->name('employees.template');
    Route::post('/employees/import', [EmployeeImportExportController::class, 'import'])->name('employees.import');
    Route::post('/employees/bulk-active', [EmployeeController::class, 'bulkActive'])->name('employees.bulk-active');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    Route::post('/employees/{employee}/notes', [EmployeeNoteController::class, 'store'])->name('employees.notes.store');
    Route::delete('/employees/{employee}/notes/{note}', [EmployeeNoteController::class, 'destroy'])->name('employees.notes.destroy');
    Route::post('/employees/{employee}/calls', [EmployeeCallLogController::class, 'store'])->name('employees.calls.store');

    // Unified documents (employee + company surfaces in Phase 2)
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{document}/exempt', [DocumentController::class, 'exempt'])->name('documents.exempt');

    // Screen 16 — Compliance Center
    Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');

    // Per-user table column visibility (§10)
    Route::put('/column-settings', [ColumnSettingsController::class, 'update'])->name('column-settings.update');

    // Bell
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Screen 02 + Screen 04 — Super Admin only
    Route::middleware('super_admin')->group(function (): void {
        Route::get('/welcome', [WelcomeController::class, 'index'])->name('welcome');
        Route::post('/welcome/{company}/select', [WelcomeController::class, 'select'])->name('welcome.select');
        Route::post('/welcome/clear', [WelcomeController::class, 'clearSelection'])->name('welcome.clear');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    });

    // Screens 17 / 25 / 26 — Super Admin + Company Admin
    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/permissions', [PermissionMatrixController::class, 'index'])->name('permissions.index');
        Route::put('/permissions/{user}', [PermissionMatrixController::class, 'update'])->name('permissions.update');

        Route::post('/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('admin.users.update');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general');
        Route::put('/settings/mail', [SettingsController::class, 'updateMail'])->name('settings.mail');
        Route::post('/settings/mail/test', [SettingsController::class, 'testMail'])->name('settings.mail.test');
    });
});
