<?php

use App\Enums\VatRate;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CompanyCardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ExpenseReviewController;
use App\Http\Controllers\Admin\OvertimePolicyController;
use App\Http\Controllers\Admin\PermissionMatrixController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordResetController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceImportExportController;
use App\Http\Controllers\AttendanceVoiceNoteController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\AutocompleteController;
use App\Http\Controllers\CallPanelController;
use App\Http\Controllers\ClientContactController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ColumnSettingsController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DocumentCenterController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeCallLogController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportExportController;
use App\Http\Controllers\EmployeeNoteController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MeasurementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductionTaskController;
use App\Http\Controllers\ProjectContactController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDesignationRateController;
use App\Http\Controllers\ProjectWorkerController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SubcontractorController;
use App\Http\Controllers\TaskProgressController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WageRateController;
use App\Http\Controllers\WeekendWorkOfferController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\Worker\WorkerController;
use App\Http\Controllers\Worker\WorkerExpenseController;
use App\Http\Controllers\Worker\WorkerVehicleController;
use App\Http\Controllers\Worker\WorkerVoiceNoteController;
use App\Http\Controllers\WorkerAccessController;
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

// URL-triggered scheduler (shell cron is unavailable on this host). An external
// service (cron-job.org) hits this every minute with the secret token; it runs
// schedule:run. Public + token-gated by design — no auth middleware.
Route::get('/cron/run', [CronController::class, 'run'])->name('cron.run');

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
// Two-step verification challenge: reachable WITHOUT a session, because the
// user is parked between password and login (SECURITY.md §1).
Route::middleware('guest')->group(function (): void {
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')->name('two-factor.verify');
});

// 'two_factor' confines anyone who has not enrolled yet to the setup screen.
/*
|--------------------------------------------------------------------------
| Worker PWA — the mobile app, and the ONLY thing a worker account can reach
|--------------------------------------------------------------------------
| 'worker' proves the account is a worker AND linked to an employee record.
| Two-step verification is deliberately absent from this group (client
| decision): crews sign in with email + password only.
*/
Route::middleware(['auth', 'active', 'worker'])->prefix('worker')->group(function (): void {
    Route::get('/', [WorkerController::class, 'home'])->name('worker.home');
    Route::post('/privacy-ack', [WorkerController::class, 'acknowledgePrivacy'])->name('worker.privacy-ack');
    Route::post('/consent', [WorkerController::class, 'updateConsent'])->name('worker.consent.update');
    Route::post('/check-in', [WorkerController::class, 'checkIn'])->name('worker.check-in');
    Route::post('/check-out', [WorkerController::class, 'checkOut'])->name('worker.check-out');
    Route::post('/absence', [WorkerController::class, 'absence'])->name('worker.absence');

    // Feature 1 — voice / text note at checkout
    Route::post('/voice-note', [WorkerVoiceNoteController::class, 'store'])->name('worker.voice-note.store');
    Route::get('/voice-notes/{voiceNote}/download', [WorkerVoiceNoteController::class, 'download'])->name('worker.voice-note.download');

    // Feature 2 — worker expense submission
    Route::post('/expenses', [WorkerExpenseController::class, 'store'])->name('worker.expense.store');

    // Feature 4 — vehicle sessions
    Route::get('/vehicles', [WorkerVehicleController::class, 'index'])->name('worker.vehicles');
    Route::post('/vehicles/{vehicle}/take', [WorkerVehicleController::class, 'take'])->name('worker.vehicles.take');
    Route::post('/vehicle-sessions/{session}/fuel', [WorkerVehicleController::class, 'logFuel'])->name('worker.vehicle-sessions.fuel');
    Route::post('/vehicle-sessions/{session}/return', [WorkerVehicleController::class, 'returnVehicle'])->name('worker.vehicle-sessions.return');

    // PWA notification bell — workers can't reach the CRM /notifications routes
    // (they are in the not_worker group), so they get their own read actions.
    // Both operate on $request->user() (the worker), so no extra scoping needed.
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('worker.notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('worker.notifications.read');
});

/*
 * The offline fallback the service worker precaches and serves when the
 * network is gone. Deliberately OUTSIDE the auth group: it is a static page
 * with no data on it, and requiring a session would defeat the point — the
 * device cannot reach the server to validate one.
 */
Route::view('/worker/offline', 'worker-offline')->name('worker.offline');

// Logout must be reachable by EVERY authenticated account, whatever its role
// or 2FA state — a worker, or a user still parked on the 2FA setup screen, has
// to be able to sign out. It therefore carries only 'auth': putting it behind
// 'not_worker' made DenyWorkers redirect a worker back to their app before the
// session was ever cleared, so their logout button appeared to do nothing.
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')->name('logout');

// 'not_worker' bounces a worker account back to their app rather than
// leaving them at a 403 on a CRM screen they can never use.
Route::middleware(['auth', 'active', 'two_factor', 'not_worker'])->group(function (): void {
    // Enrolment — RequireTwoFactor confines an un-enrolled user to these.
    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/setup', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::get('/two-factor/recovery', [TwoFactorController::class, 'recovery'])->name('two-factor.recovery');

    // My Account — self-service profile + own 2FA (any authenticated CRM user).
    // Name only; password is a request to a Super Admin; 2FA re-config behind a
    // password re-check.
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
    Route::post('/account/password-reset-request', [AccountController::class, 'requestPasswordReset'])->name('account.password-request');
    Route::post('/account/two-factor/reconfigure', [AccountController::class, 'reconfigureTwoFactor'])->name('account.two-factor.reconfigure');
    Route::post('/account/two-factor/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->name('account.two-factor.recovery-codes');

    // Screen 03 — Dashboard (Phase 8). Per selected company; SA without a
    // selection is redirected to Welcome to pick one.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Screen 15 — Today's Report (Phase 8). Live view, client auto-refresh.
    Route::get('/today/export', [TodayController::class, 'export'])->name('today.export');
    Route::get('/today/breakdown-export', [TodayController::class, 'exportBreakdown'])->name('today.breakdown-export');
    Route::get('/today/projects-export', [TodayController::class, 'exportProjects'])->name('today.projects-export');
    Route::get('/today', [TodayController::class, 'index'])->name('today.index');

    // Global search (Phase 8) — JSON for the header dropdown; permission- and
    // company-scoped in GlobalSearch. The only non-Inertia GET in the app.
    Route::get('/search', SearchController::class)->name('search');

    // Autocomplete (JSON) — previous free-text values for a field, company
    // scoped. Currently powers invoice/expense line-item descriptions.
    Route::get('/autocomplete/descriptions', [AutocompleteController::class, 'descriptions'])->name('autocomplete.descriptions');

    // Screen 14 — Reports (Phase 8). Export routes before index so
    // /reports/export-* never resolve as a module param.
    Route::get('/reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export.excel');
    Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

    // Screen 05/06 — Employees (module permissions checked in controllers)
    Route::get('/employees/export', [EmployeeImportExportController::class, 'export'])->name('employees.export');
    Route::get('/employees/template', [EmployeeImportExportController::class, 'template'])->name('employees.template');
    Route::post('/employees/import', [EmployeeImportExportController::class, 'import'])->name('employees.import');
    Route::post('/employees/bulk-active', [EmployeeController::class, 'bulkActive'])->name('employees.bulk-active');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    // NB: param is {employeeId} (not {employee}) so implicit binding does NOT
    // apply here — show() resolves manually to allow an old company to open a
    // worker who has since transferred away (read-only). update/destroy keep
    // {employee} + the tenant-scoped binding, so writes still 404 cross-company.
    Route::get('/employees/{employeeId}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    Route::post('/employees/{employee}/transfer', [EmployeeController::class, 'transfer'])->name('employees.transfer');
    Route::post('/employees/{employee}/wage-rates', [WageRateController::class, 'store'])->name('employees.wage-rates.store');
    Route::delete('/employees/{employee}/wage-rates/{wageRate}', [WageRateController::class, 'destroy'])->name('employees.wage-rates.destroy');
    Route::post('/employees/{employee}/notes', [EmployeeNoteController::class, 'store'])->name('employees.notes.store');
    Route::delete('/employees/{employee}/notes/{note}', [EmployeeNoteController::class, 'destroy'])->name('employees.notes.destroy');
    Route::post('/employees/{employee}/calls', [EmployeeCallLogController::class, 'store'])->name('employees.calls.store');
    // Mobile PWA access for this employee (Worker PWA, Phase B)
    Route::post('/employees/{employee}/app-access', [WorkerAccessController::class, 'store'])->name('employees.app-access.store');
    Route::delete('/employees/{employee}/app-access', [WorkerAccessController::class, 'destroy'])->name('employees.app-access.destroy');
    // Privacy-consent: admin reset (force re-accept) + per-record PDF (evidence).
    Route::post('/employees/{employee}/consent/reset', [EmployeeController::class, 'resetConsent'])->name('employees.consent.reset');
    Route::get('/employees/{employee}/consent/{consent}/pdf', [EmployeeController::class, 'consentPdf'])->name('employees.consent.pdf');

    // Global Document Command Center — /documents index (before {document} routes)
    Route::get('/documents', [DocumentCenterController::class, 'index'])->name('documents.index');
    Route::get('/documents/export', [DocumentCenterController::class, 'export'])->name('documents.export');
    Route::post('/documents/bulk-exempt', [DocumentCenterController::class, 'bulkExempt'])->name('documents.bulk_exempt');

    // Unified documents (employee + company surfaces in Phase 2)
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}/panel', [DocumentCenterController::class, 'panel'])->name('documents.panel');
    Route::post('/documents/{document}/replace', [DocumentController::class, 'replace'])->name('documents.replace');
    Route::patch('/documents/{document}/metadata', [DocumentController::class, 'updateMetadata'])->name('documents.metadata');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{document}/exempt', [DocumentController::class, 'exempt'])->name('documents.exempt');

    // Screen 16 — Compliance Center, superseded by the Document Command Center.
    Route::get('/compliance', fn () => redirect('/documents'))->name('compliance.index');

    // Screen 07 — Clients (shared pool; module-permission gated in controllers)
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::post('/clients/{client}/contacts', [ClientContactController::class, 'storeContact'])->name('clients.contacts.store');
    Route::delete('/clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroyContact'])->name('clients.contacts.destroy');
    Route::post('/clients/{client}/communications', [ClientContactController::class, 'storeCommunication'])->name('clients.communications.store');

    // Screen 20 — Vendors (shared pool)
    Route::get('/vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::post('/vendors', [VendorController::class, 'store'])->name('vendors.store');
    Route::get('/vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
    Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy'])->name('vendors.destroy');
    Route::post('/vendors/{vendor}/contacts', [VendorController::class, 'storeContact'])->name('vendors.contacts.store');
    Route::post('/vendors/{vendor}/payment-terms', [VendorController::class, 'storePaymentTerm'])->name('vendors.terms.store');

    // Screens 08/09 — Projects (company-owned)
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::put('/projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.status');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/workers', [ProjectWorkerController::class, 'store'])->name('projects.workers.store');
    Route::delete('/projects/{project}/workers/{rate}', [ProjectWorkerController::class, 'destroy'])->name('projects.workers.destroy');
    // Feature 2 — per-designation rates (client + worker) on a project.
    Route::post('/projects/{project}/designation-rates', [ProjectDesignationRateController::class, 'store'])->name('projects.designation-rates.store');
    Route::delete('/projects/{project}/designation-rates/{designationRate}', [ProjectDesignationRateController::class, 'destroy'])->name('projects.designation-rates.destroy');

    // Client-side contacts for a project (supervisor / engineer / PM / other).
    Route::post('/projects/{project}/contacts', [ProjectContactController::class, 'store'])->name('projects.contacts.store');
    Route::put('/projects/{project}/contacts/{contact}', [ProjectContactController::class, 'update'])->name('projects.contacts.update');
    Route::delete('/projects/{project}/contacts/{contact}', [ProjectContactController::class, 'destroy'])->name('projects.contacts.destroy');

    // Production tasks — standalone screen (all tasks across projects) + exports.
    Route::get('/tasks/export', [ProductionTaskController::class, 'export'])->name('tasks.export');
    Route::get('/tasks/export-pdf', [ProductionTaskController::class, 'exportPdf'])->name('tasks.export-pdf');
    Route::get('/tasks', [ProductionTaskController::class, 'index'])->name('tasks.index');

    // Production tasks — internal planned-vs-actual tracker, nested under project.
    Route::post('/projects/{project}/tasks', [ProductionTaskController::class, 'store'])->name('projects.tasks.store');
    Route::put('/projects/{project}/tasks/{task}', [ProductionTaskController::class, 'update'])->name('projects.tasks.update');
    Route::delete('/projects/{project}/tasks/{task}', [ProductionTaskController::class, 'destroy'])->name('projects.tasks.destroy');

    // Daily production entry — log work against a task (multi-worker split).
    Route::get('/projects/{project}/present-workers', [TaskProgressController::class, 'presentWorkers'])->name('projects.present-workers');
    Route::post('/projects/{project}/tasks/{task}/progress', [TaskProgressController::class, 'store'])->name('projects.tasks.progress.store');
    Route::delete('/projects/{project}/tasks/{task}/progress/{batch}', [TaskProgressController::class, 'destroy'])->name('projects.tasks.progress.destroy');
    Route::get('/task-progress/{taskProgress}/photo', [TaskProgressController::class, 'photo'])->name('task-progress.photo');

    // Task templates — a company's reusable production-task catalogue.
    Route::post('/task-templates', [TaskTemplateController::class, 'store'])->name('task-templates.store');
    Route::put('/task-templates/{taskTemplate}', [TaskTemplateController::class, 'update'])->name('task-templates.update');
    Route::delete('/task-templates/{taskTemplate}', [TaskTemplateController::class, 'destroy'])->name('task-templates.destroy');

    Route::post('/projects/{project}/remarks', [ProjectWorkerController::class, 'storeRemark'])->name('projects.remarks.store');
    Route::post('/projects/{project}/alerts', [ProjectWorkerController::class, 'storeAlert'])->name('projects.alerts.store');

    // Screen 11 — Attendance (company-owned)
    // Timesheet — weekly/monthly per-employee attendance view (F3)
    Route::get('/timesheet/export', [TimesheetController::class, 'export'])->name('timesheet.export');
    Route::get('/timesheet', [TimesheetController::class, 'index'])->name('timesheet.index');

    Route::get('/attendance/export', [AttendanceImportExportController::class, 'export'])->name('attendance.export');
    Route::get('/attendance/panel-export', [AttendanceController::class, 'exportPanel'])->name('attendance.panel-export');
    Route::get('/attendance/template', [AttendanceImportExportController::class, 'template'])->name('attendance.template');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::post('/attendance/bulk', [AttendanceController::class, 'storeBulk'])->name('attendance.bulk');
    Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update');
    Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
    // Worker PWA: the gated, audited check-in selfie (Phase E)
    Route::get('/attendance/{attendance}/selfie', [AttendanceController::class, 'selfie'])->name('attendance.selfie');
    // Worker PWA: the gated, audited proof-of-work attachment (check-out)
    Route::get('/attendance/{attendance}/checkout-attachment/{which?}', [AttendanceController::class, 'checkOutAttachment'])
        ->whereIn('which', ['1', '2', '3'])->name('attendance.checkout-attachment');

    // Weekend Work Offers — an admin opens a specific weekend date for invited workers.
    Route::post('/weekend-offers', [WeekendWorkOfferController::class, 'store'])->name('weekend-offers.store');
    Route::delete('/weekend-offers/{weekendOffer}', [WeekendWorkOfferController::class, 'destroy'])->name('weekend-offers.destroy');

    // Screen 24 — Measurements (company-owned)
    Route::get('/measurements', [MeasurementController::class, 'index'])->name('measurements.index');
    // Export routes BEFORE the {measurement} routes so they are not swallowed.
    Route::get('/measurements/export', [MeasurementController::class, 'export'])->name('measurements.export');
    Route::get('/measurements/export-pdf', [MeasurementController::class, 'exportPdf'])->name('measurements.export-pdf');
    Route::post('/measurements', [MeasurementController::class, 'store'])->name('measurements.store');
    Route::put('/measurements/{measurement}', [MeasurementController::class, 'update'])->name('measurements.update');
    Route::delete('/measurements/{measurement}', [MeasurementController::class, 'destroy'])->name('measurements.destroy');
    Route::post('/measurements/{measurement}/approve', [MeasurementController::class, 'approve'])->name('measurements.approve');
    Route::post('/measurements/{measurement}/reject', [MeasurementController::class, 'reject'])->name('measurements.reject');
    Route::post('/measurements/{measurement}/reset', [MeasurementController::class, 'reset'])->name('measurements.reset');

    // Screen 10 — Invoices (Ventas / Gastos tabs) + payments
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
    Route::get('/invoices/project-costs', [InvoiceController::class, 'projectCosts'])->name('invoices.project-costs');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    // Screen 10 Gastos / Screen 09 Tab 6 — expenses
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    // Export routes BEFORE any /expenses/{param} route (decision 30).
    Route::get('/expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
    Route::get('/expenses/export-pdf', [ExpenseController::class, 'exportPdf'])->name('expenses.export-pdf');
    // Receipt-document exports (tax filing) — literal paths BEFORE /expenses/{expense}.
    Route::get('/expenses/receipts/export/zip', [ExpenseReceiptController::class, 'zip'])->name('expenses.receipts.zip');
    Route::get('/expenses/receipts/export/pdf', [ExpenseReceiptController::class, 'pdf'])->name('expenses.receipts.pdf');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    // Direct vehicle expense entry (Part D) — before /expenses/{expense}.
    Route::post('/expenses/vehicle', [ExpenseController::class, 'storeVehicle'])->name('expenses.vehicle.store');
    Route::get('/vehicles/{vehicle}/drivers-on-date', [ExpenseController::class, 'driversOnDate'])->name('vehicles.drivers-on-date');
    Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->name('expenses.receipt');
    Route::get('/expenses/{expense}/receipt/preview', [ExpenseReceiptController::class, 'preview'])->name('expenses.receipt.preview');
    // Host-side deployment cross-charge detail (worker anonymised) — fetched on demand.
    Route::get('/expenses/{expense}/deployment-detail', [ExpenseController::class, 'deploymentDetail'])->name('expenses.deployment-detail');
    Route::post('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
    Route::post('/expenses/{expense}/review', [ExpenseController::class, 'sendToReview'])->name('expenses.review');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Custom expense categories (managed inline on the Gastos screen)
    Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
    Route::put('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
    Route::delete('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');

    // Item 5 (2026-09-12) — the Worker Expenses admin tab was removed. Worker PWA
    // submissions now mint a mirror Expense at submission and are reviewed in the
    // regular Expenses tab (approve/reject via /expenses/{expense}/approve,
    // escalate via /expenses/{expense}/review, SA queue at /expense-review).

    // Feature 1 — Voice note admin download
    Route::get('/attendance/voice-notes/{voiceNote}/download', [AttendanceVoiceNoteController::class, 'download'])->name('attendance.voice-note.download');

    // Screen 19 — Commission reports (finalizing is a one-way door)
    Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::post('/commissions/generate', [CommissionController::class, 'generate'])->name('commissions.generate');
    Route::get('/commissions/pdf', [CommissionController::class, 'pdf'])->name('commissions.pdf');
    Route::get('/commissions/export', [CommissionController::class, 'export'])->name('commissions.export');
    Route::put('/commissions/{entry}/adjust', [CommissionController::class, 'adjust'])->name('commissions.adjust');
    Route::post('/commissions/{entry}/finalize', [CommissionController::class, 'finalize'])->name('commissions.finalize');
    Route::post('/commissions/{entry}/paid', [CommissionController::class, 'markPaid'])->name('commissions.paid');

    // Screen 12 — Payroll (company-owned; locked periods reject dated edits)
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/export', [PayrollController::class, 'export'])->name('payroll.export');
    Route::get('/payroll/payslips', [PayrollController::class, 'payslips'])->name('payroll.payslips');
    Route::post('/payroll/calculate', [PayrollController::class, 'calculate'])->name('payroll.calculate');
    Route::post('/payroll/approve-all', [PayrollController::class, 'approveAll'])->name('payroll.approve-all');
    // Bulk actions on selected rows (register before /payroll/{payroll})
    Route::post('/payroll/bulk-approve', [PayrollController::class, 'bulkApprove'])->name('payroll.bulk-approve');
    Route::post('/payroll/bulk-paid', [PayrollController::class, 'bulkMarkPaid'])->name('payroll.bulk-paid');
    Route::get('/payroll/bulk-export', [PayrollController::class, 'bulkExport'])->name('payroll.bulk-export');
    Route::post('/payroll/lock', [PayrollController::class, 'lockPeriod'])->name('payroll.lock');
    Route::post('/payroll/unlock', [PayrollController::class, 'unlockPeriod'])->name('payroll.unlock');
    Route::get('/payroll/{payroll}/payslip', [PayrollController::class, 'payslip'])->name('payroll.payslip');
    Route::post('/payroll/{payroll}/paid', [PayrollController::class, 'markPaid'])->name('payroll.paid');
    Route::put('/payroll/{payroll}/adjust', [PayrollController::class, 'adjust'])->name('payroll.adjust');
    Route::post('/payroll/{payroll}/recalculate', [PayrollController::class, 'recalculate'])->name('payroll.recalculate');

    // Salary advances (payroll module)
    Route::post('/advances', [AdvanceController::class, 'store'])->name('advances.store');
    Route::get('/advances/{advance}/receipt', [AdvanceController::class, 'downloadReceipt'])->name('advances.receipt');
    Route::post('/advances/{advance}', [AdvanceController::class, 'update'])->name('advances.update');
    Route::post('/advances/{advance}/decide', [AdvanceController::class, 'decide'])->name('advances.decide');
    Route::delete('/advances/{advance}', [AdvanceController::class, 'destroy'])->name('advances.destroy');
    Route::post('/advance-categories', [AdvanceController::class, 'storeCategory'])->name('advance-categories.store');

    // Screen 12 — Cross-company employee deployments (spans two companies)
    Route::get('/deployments', [DeploymentController::class, 'index'])->name('deployments.index');
    Route::get('/deployments/available-employees', [DeploymentController::class, 'availableEmployees'])->name('deployments.available-employees');
    Route::post('/deployments', [DeploymentController::class, 'store'])->name('deployments.store');
    Route::put('/deployments/{deployment}', [DeploymentController::class, 'update'])->name('deployments.update');
    Route::post('/deployments/{deployment}/complete', [DeploymentController::class, 'complete'])->name('deployments.complete');
    Route::post('/deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
    // Host-side settlement: mark the cross-charge paid/unpaid (never touches P&L).
    Route::post('/deployments/{deployment}/settlement', [DeploymentController::class, 'settlement'])->name('deployments.settlement');
    Route::get('/deployments/{deployment}/invoice-pdf', [DeploymentController::class, 'invoicePdf'])->name('deployments.invoice_pdf');

    // Screen 13 — Call Panel (Phase 7). Same employee_call_logs rows as the
    // employee Llamadas tab, with follow-up triage on top.
    Route::get('/calls', [CallPanelController::class, 'index'])->name('calls.index');
    Route::post('/calls', [CallPanelController::class, 'store'])->name('calls.store');
    Route::get('/calls/{call}/download', [CallPanelController::class, 'download'])->name('calls.download');
    Route::patch('/calls/{call}/label', [CallPanelController::class, 'rename'])->name('calls.rename');

    // Screen 23 — Inventory (Phase 7). Every stock change goes through
    // StockMovementService: the ledger and the item counters move together.
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
    Route::post('/inventory/items', [InventoryController::class, 'store'])->name('inventory.items.store');
    Route::put('/inventory/items/{item}', [InventoryController::class, 'update'])->name('inventory.items.update');
    Route::delete('/inventory/items/{item}', [InventoryController::class, 'destroy'])->name('inventory.items.destroy');
    Route::post('/inventory/items/{item}/movements', [InventoryController::class, 'storeMovement'])->name('inventory.movements.store');
    Route::post('/inventory/items/{item}/issue', [InventoryController::class, 'issue'])->name('inventory.issue');
    Route::post('/inventory/items/{item}/assign', [InventoryController::class, 'assignToProject'])->name('inventory.assign');
    Route::post('/inventory/issues/{issue}/return', [InventoryController::class, 'returnIssue'])->name('inventory.issues.return');
    Route::post('/inventory/assignments/{assignment}/return', [InventoryController::class, 'returnAssignment'])->name('inventory.assignments.return');
    Route::post('/inventory/categories', [InventoryController::class, 'storeCategory'])->name('inventory.categories.store');
    Route::put('/inventory/categories/{category}', [InventoryController::class, 'updateCategory'])->name('inventory.categories.update');
    Route::delete('/inventory/categories/{category}', [InventoryController::class, 'destroyCategory'])->name('inventory.categories.destroy');

    // Screen 21 — Vehicles (Phase 7). Insurance/ITV expiries feed the same
    // compliance alerts as documents (VehicleCompliance + verto:scan-documents).
    Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('vehicles.destroy');
    Route::post('/vehicles/{vehicle}/assign', [VehicleController::class, 'assign'])->name('vehicles.assign');
    Route::post('/vehicles/{vehicle}/maintenance', [VehicleController::class, 'storeMaintenance'])->name('vehicles.maintenance.store');
    Route::delete('/vehicles/{vehicle}/maintenance/{maintenance}', [VehicleController::class, 'destroyMaintenance'])->name('vehicles.maintenance.destroy');
    Route::post('/vehicles/{vehicle}/mileage', [VehicleController::class, 'storeMileage'])->name('vehicles.mileage.store');
    Route::post('/vehicles/{vehicle}/daily-assignments', [VehicleController::class, 'storeDailyAssignment'])->name('vehicles.daily-assignments.store');
    Route::delete('/vehicles/{vehicle}/daily-assignments/{assignment}', [VehicleController::class, 'destroyDailyAssignment'])->name('vehicles.daily-assignments.destroy');
    Route::post('/vehicles/{vehicle}/fines', [VehicleController::class, 'storeFine'])->name('vehicles.fines.store');
    Route::delete('/vehicles/{vehicle}/fines/{fine}', [VehicleController::class, 'destroyFine'])->name('vehicles.fines.destroy');
    Route::put('/vehicles/{vehicle}/fines/{fine}/deduct-salary', [VehicleController::class, 'deductFine'])->name('vehicles.fines.deduct');
    Route::post('/vehicles/{vehicle}/fuel', [VehicleController::class, 'storeFuel'])->name('vehicles.fuel.store');
    Route::delete('/vehicles/{vehicle}/fuel/{fuelRecord}', [VehicleController::class, 'destroyFuel'])->name('vehicles.fuel.destroy');
    // Worker session condition media — gated + audited, company-scoped via the vehicle.
    Route::get('/vehicles/{vehicle}/sessions/{session}/photo/{which}', [VehicleController::class, 'sessionPhoto'])->name('vehicles.sessions.photo');
    Route::get('/vehicles/{vehicle}/sessions/{session}/voice/{which}', [VehicleController::class, 'sessionVoice'])->name('vehicles.sessions.voice');

    // Subcontratistas (thaekedar). Marking a payment paid posts a Gasto on the
    // subcontractor's own company + project — see SubcontractorService.
    Route::get('/subcontractors', [SubcontractorController::class, 'index'])->name('subcontractors.index');
    Route::post('/subcontractors', [SubcontractorController::class, 'store'])->name('subcontractors.store');
    Route::get('/subcontractors/{subcontractor}', [SubcontractorController::class, 'show'])->name('subcontractors.show');
    Route::put('/subcontractors/{subcontractor}', [SubcontractorController::class, 'update'])->name('subcontractors.update');
    Route::delete('/subcontractors/{subcontractor}', [SubcontractorController::class, 'destroy'])->name('subcontractors.destroy');
    Route::post('/subcontractors/{subcontractor}/workers', [SubcontractorController::class, 'storeWorker'])->name('subcontractors.workers.store');
    Route::put('/subcontractors/{subcontractor}/workers/{worker}', [SubcontractorController::class, 'updateWorker'])->name('subcontractors.workers.update');
    Route::delete('/subcontractors/{subcontractor}/workers/{worker}', [SubcontractorController::class, 'destroyWorker'])->name('subcontractors.workers.destroy');
    Route::post('/subcontractors/{subcontractor}/payments', [SubcontractorController::class, 'storePayment'])->name('subcontractors.payments.store');
    Route::post('/subcontractors/{subcontractor}/payments/{payment}/paid', [SubcontractorController::class, 'markPaid'])->name('subcontractors.payments.paid');
    Route::post('/subcontractors/{subcontractor}/payments/{payment}/pending', [SubcontractorController::class, 'markPending'])->name('subcontractors.payments.pending');
    Route::delete('/subcontractors/{subcontractor}/payments/{payment}', [SubcontractorController::class, 'destroyPayment'])->name('subcontractors.payments.destroy');

    // Screen 22 — Leave management (Phase 7). Approving books the days into
    // the attendance grid, so these go through LeaveService, not the model.
    Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('/leave/{leave}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
    Route::post('/leave/{leave}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
    Route::post('/leave/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
    Route::get('/leave/{leave}/attachment', [LeaveController::class, 'download'])->name('leave.attachment');
    Route::put('/leave-balances/{balance}', [LeaveController::class, 'adjustBalance'])->name('leave-balances.update');

    // Screen 18 — Proposals (shared pool)
    Route::get('/proposals', [ProposalController::class, 'index'])->name('proposals.index');
    Route::post('/proposals', [ProposalController::class, 'store'])->name('proposals.store');
    Route::put('/proposals/{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
    Route::delete('/proposals/{proposal}', [ProposalController::class, 'destroy'])->name('proposals.destroy');
    Route::get('/proposals/{proposal}/pdf', [ProposalController::class, 'pdf'])->name('proposals.pdf');

    // Per-user table column visibility (§10)
    Route::put('/column-settings', [ColumnSettingsController::class, 'update'])->name('column-settings.update');

    // Multi-company Admins/Managers switch between their assigned companies
    // (pivot-validated in CurrentCompany::select — 403 for anyone else).
    Route::post('/company/{company}/switch', CompanySwitchController::class)->name('company.switch');

    // Bell + notifications page
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/delete-read', [NotificationController::class, 'deleteRead'])->name('notifications.delete-read');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Screen 02 + Screen 04 — Super Admin only
    Route::middleware('super_admin')->group(function (): void {
        // Part C — Expenses awaiting review (Super Admin final decision).
        Route::get('/expense-review', [ExpenseReviewController::class, 'index'])->name('expense-review.index');
        Route::post('/expense-review/{expense}/approve', [ExpenseReviewController::class, 'approve'])->name('expense-review.approve');
        Route::post('/expense-review/{expense}/reject', [ExpenseReviewController::class, 'reject'])->name('expense-review.reject');

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
        // Company assignment for Admins and Managers (SA + Admin guarded in controller)
        Route::post('/permissions/{user}/companies', [PermissionMatrixController::class, 'assignCompany'])->name('permissions.assign-company');
        Route::delete('/permissions/{user}/companies/{company}', [PermissionMatrixController::class, 'removeCompany'])->name('permissions.remove-company');
        // Super Admin clears a user's second factor (lost phone) — guarded in
        // the controller, not just by the admin group.
        Route::post('/permissions/{user}/reset-2fa', [TwoFactorController::class, 'reset'])->name('two-factor.reset');
        // Super Admin sends a password reset link — the fulfillment side of a
        // user's My Account "request password reset" (SA-only, guarded there).
        Route::post('/permissions/{user}/reset-password', [UserPasswordResetController::class, 'send'])->name('users.reset-password');

        Route::post('/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general');
        // Per-company auto day-type thresholds (Screen 26).
        Route::put('/settings/attendance', [SettingsController::class, 'updateAttendance'])->name('settings.attendance');
        // Legal → worker-consent notice version (brand-wide re-accept trigger).
        Route::put('/settings/legal', [SettingsController::class, 'updateLegal'])->name('settings.legal');
        // Settings → Company profile (name/CIF/address/logo) + working days
        Route::post('/settings/company-profile', [SettingsController::class, 'updateCompanyProfile'])->name('settings.company-profile');
        Route::put('/settings/working-days', [SettingsController::class, 'updateWorkingDays'])->name('settings.working-days');
        // Settings → Departamentos (company-scoped catalogue for the employee form)
        Route::post('/settings/departments', [DepartmentController::class, 'store'])->name('settings.departments.store');
        Route::put('/settings/departments/{department}', [DepartmentController::class, 'update'])->name('settings.departments.update');
        Route::delete('/settings/departments/{department}', [DepartmentController::class, 'destroy'])->name('settings.departments.destroy');
        // Settings → Tarjetas de empresa (company payment cards for the expense form)
        Route::post('/settings/company-cards', [CompanyCardController::class, 'store'])->name('settings.company-cards.store');
        Route::put('/settings/company-cards/{companyCard}', [CompanyCardController::class, 'update'])->name('settings.company-cards.update');
        Route::delete('/settings/company-cards/{companyCard}', [CompanyCardController::class, 'destroy'])->name('settings.company-cards.destroy');
        Route::put('/settings/mail', [SettingsController::class, 'updateMail'])->name('settings.mail');
        Route::post('/settings/mail/test', [SettingsController::class, 'testMail'])->name('settings.mail.test');
        // Screen 26 — notification rules matrix (Phase 8, Super Admin only)
        Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications');

        // Settings → Overtime policies (Phase 4)
        Route::post('/overtime-policies', [OvertimePolicyController::class, 'store'])->name('overtime-policies.store');
        Route::put('/overtime-policies/{overtime_policy}', [OvertimePolicyController::class, 'update'])->name('overtime-policies.update');
        Route::delete('/overtime-policies/{overtime_policy}', [OvertimePolicyController::class, 'destroy'])->name('overtime-policies.destroy');
    });
});
