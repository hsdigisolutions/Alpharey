<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OvertimePolicyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestMailRequest;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\UpdateMailSettingsRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimePolicy;
use App\Services\Attendance\AttendanceService;
use App\Services\Notifications\NotificationRules;
use App\Services\Settings\MailSettings;
use App\Services\Settings\SettingsService;
use App\Services\System\SystemHealth;
use App\Support\CurrentCompany;
use App\Support\WorkerPrivacyNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 26 — Settings, Phase 1 sections: General + Email. Further
 * sections (document alerts, overtime policies, categories, teams…)
 * land with their owning phases.
 */
class SettingsController extends Controller
{
    public function index(Request $request, SettingsService $settings, MailSettings $mail, NotificationRules $rules, SystemHealth $health): Response
    {
        $user = $request->user();
        $isSuperAdmin = $user !== null && $user->isSuperAdmin();

        return Inertia::render('Admin/Settings', [
            'general' => [
                'app_name' => $settings->get('general.app_name', 'AlphaRey'),
                'default_locale' => $settings->get('general.default_locale', 'es'),
                'timezone' => $settings->get('general.timezone', 'Europe/Madrid'),
                'session_timeout_minutes' => $settings->get('general.session_timeout_minutes', 120),
            ],
            // SMTP configuration is Super Admin-only — never shipped to others
            'mail' => $isSuperAdmin ? $mail->current() : null,
            'canManageMail' => $isSuperAdmin,
            // Overtime policies (Phase 4) — company-scoped via the global scope
            'overtimePolicies' => OvertimePolicy::query()->orderBy('name')->get([
                'id', 'name', 'type', 'rate', 'daily_threshold_hours', 'accumulate_hours_per_day', 'notes',
            ]),
            'overtimeTypes' => array_map(fn (OvertimePolicyType $t) => $t->value, OvertimePolicyType::cases()),
            // Auto day-type thresholds (hours) + max check-out distance (metres),
            // per the active company.
            'dayTypeThresholds' => app(AttendanceService::class)
                ->dayTypeThresholds(app(CurrentCompany::class)->id() ?? 0),
            'maxLocationDistance' => app(AttendanceService::class)
                ->maxLocationDistance(app(CurrentCompany::class)->id() ?? 0),
            'offSiteAlertDistance' => app(AttendanceService::class)
                ->offSiteAlertDistance(app(CurrentCompany::class)->id() ?? 0),
            // Standard unpaid break (minutes) deducted from a full-day shift's
            // displayed hours (08:00–17:00 → 8 h). Display only.
            'breakDurationMinutes' => app(AttendanceService::class)
                ->breakDurationMinutes(app(CurrentCompany::class)->id() ?? 0),
            // Company profile (name / CIF / address / logo) of the acting company
            // — feeds invoices + payslips. Null when no single company is selected.
            'companyProfile' => $this->companyProfilePayload(),
            // Working days for the acting company (ISO weekday numbers, 1=Mon).
            // Drives absence tracking; default Mon–Fri.
            'workingDays' => app(AttendanceService::class)
                ->workingDays(app(CurrentCompany::class)->id() ?? 0),
            // Departamentos — the acting company's department catalogue (with a
            // live employee count per row for the delete guard). Empty when a
            // Super Admin has no single company selected (nothing to manage).
            'departments' => $this->departmentsPayload(),
            // Legal → the current worker-consent notice version (brand-wide).
            'consentVersion' => WorkerPrivacyNotice::currentVersion(),
            // Notification matrix + system health are brand-level → Super Admin only
            'notificationMatrix' => $isSuperAdmin ? $rules->matrix() : null,
            'systemHealth' => $isSuperAdmin ? $health->check() : null,
        ]);
    }

    /**
     * The acting company's departments with a live employee count per row.
     *
     * @return list<array{id: int, name: string, active: bool, employee_count: int}>
     */
    private function departmentsPayload(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        if ($companyId === null) {
            return [];
        }

        // Employees-per-department in one grouped query, keyed by department_id.
        $counts = Employee::query()
            ->whereNotNull('department_id')
            ->selectRaw('department_id, COUNT(*) as c')
            ->groupBy('department_id')
            ->pluck('c', 'department_id');

        return Department::query()->orderBy('name')->get(['id', 'name', 'active'])
            ->map(fn (Department $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'active' => $d->active,
                'employee_count' => (int) ($counts[$d->id] ?? 0),
            ])->all();
    }

    /**
     * The acting company's profile for the Settings card. Null when no single
     * company is selected. The logo ships as a base64 data URI for preview
     * (the CSP forbids external images; the file lives on the private disk).
     *
     * @return array{name: string, cif: ?string, address: ?string, logo: ?string}|null
     */
    private function companyProfilePayload(): ?array
    {
        $company = Company::query()->find(app(CurrentCompany::class)->id());
        if ($company === null) {
            return null;
        }

        $logo = null;
        $path = $company->logo_path;
        if ($path !== null && $path !== '') {
            foreach (['public', 'local'] as $disk) {
                if (Storage::disk($disk)->exists($path)) {
                    $bytes = Storage::disk($disk)->get($path);
                    if ($bytes !== null) {
                        $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';
                        $logo = 'data:'.$mime.';base64,'.base64_encode($bytes);
                    }
                    break;
                }
            }
        }

        return [
            'name' => $company->name,
            'cif' => $company->cif,
            'address' => $company->address,
            'logo' => $logo,
        ];
    }

    /**
     * Update the acting company's profile (name / CIF / address) and, when a
     * file is supplied, its logo (stored on the private disk; the old one is
     * removed). These feed the invoice + payslip PDFs.
     */
    public function updateCompanyProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);

        $company = Company::query()->find(app(CurrentCompany::class)->id());
        abort_if($company === null, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cif' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $company->name = $data['name'];
        $company->cif = $data['cif'] ?? null;
        $company->address = $data['address'] ?? null;

        if ($request->hasFile('logo')) {
            if ($company->logo_path !== null && Storage::disk('local')->exists($company->logo_path)) {
                Storage::disk('local')->delete($company->logo_path);
            }
            // Randomised name on the PRIVATE disk (Rule 10); read back for the
            // PDF/preview via base64, never a public URL.
            $company->logo_path = $request->file('logo')->store("company-logos/{$company->id}", 'local');
        }

        $company->save();

        return back()->with('success', __('ui.settings.saved'));
    }

    /**
     * Set the acting company's working days (ISO weekday numbers, 1=Mon…7=Sun).
     * Drives absence tracking (a non-working day is never an absence).
     */
    public function updateWorkingDays(Request $request, SettingsService $settings): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);

        $companyId = app(CurrentCompany::class)->id();
        abort_if($companyId === null, 403);

        $data = $request->validate([
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
        ]);

        $days = array_values(array_unique(array_map('intval', $data['working_days'])));
        sort($days);
        $settings->set("attendance.working_days.{$companyId}", $days);

        return back()->with('success', __('ui.settings.saved'));
    }

    public function updateNotifications(Request $request, NotificationRules $rules): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 403);

        $validated = $request->validate([
            'matrix' => ['required', 'array'],
            'matrix.*.type' => ['required', 'string'],
            'matrix.*.roles' => ['required', 'array'],
        ]);

        $rules->save($validated['matrix']);

        return back()->with('success', __('ui.settings.saved'));
    }

    /**
     * Per-company auto day-type thresholds (hours). Half must not exceed full.
     * Stored under company-scoped keys so each company grades its own days.
     */
    public function updateAttendance(Request $request, SettingsService $settings): RedirectResponse
    {
        // Explicit authorization (not only the `admin` route middleware) — the
        // same super/company-admin gate the other settings writes enforce.
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);

        $companyId = app(CurrentCompany::class)->id();
        abort_if($companyId === null, 403);

        $validated = $request->validate([
            'full_day_threshold' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'half_day_threshold' => ['required', 'numeric', 'min:0', 'max:24', 'lte:full_day_threshold'],
            // Max check-out distance from check-in (metres) before a mismatch alert.
            'max_location_distance' => ['required', 'numeric', 'min:50', 'max:100000'],
            // Distance from the PROJECT site (metres) above which a check-in is off site.
            'off_site_alert_distance' => ['required', 'integer', 'min:100', 'max:100000'],
            // Standard unpaid break (minutes) deducted from a full-day's displayed hours.
            'break_duration_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);

        $settings->set("attendance.full_day_threshold.{$companyId}", (float) $validated['full_day_threshold']);
        $settings->set("attendance.half_day_threshold.{$companyId}", (float) $validated['half_day_threshold']);
        $settings->set("attendance.max_location_distance.{$companyId}", (float) $validated['max_location_distance']);
        $settings->set("attendance.off_site_alert_distance.{$companyId}", (int) $validated['off_site_alert_distance']);
        $settings->set("attendance.break_duration_minutes.{$companyId}", (int) $validated['break_duration_minutes']);

        return back()->with('success', __('ui.settings.saved'));
    }

    /**
     * Bump the worker-consent notice version (Settings → Legal). Brand-wide: a
     * new version forces every worker to re-accept before their next punch.
     */
    public function updateLegal(Request $request, SettingsService $settings): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);

        $validated = $request->validate([
            'consent_version' => ['required', 'string', 'max:40'],
        ]);

        $settings->set('legal.consent_version', $validated['consent_version']);

        return back()->with('success', __('ui.settings.saved'));
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->set('general.app_name', $request->validated('app_name'));
        $settings->set('general.default_locale', $request->validated('default_locale'));
        $settings->set('general.timezone', $request->validated('timezone'));
        $settings->set('general.session_timeout_minutes', (int) $request->validated('session_timeout_minutes'));

        return back()->with('success', __('ui.settings.saved'));
    }

    public function updateMail(UpdateMailSettingsRequest $request, MailSettings $mail): RedirectResponse
    {
        /** @var array{host: string, port: int, username: ?string, password: ?string, encryption: string, from_name: string, from_address: string} $values */
        $values = $request->validated();

        $mail->save($values);

        return back()->with('success', __('ui.settings.saved'));
    }

    public function testMail(TestMailRequest $request, MailSettings $mail): RedirectResponse
    {
        try {
            $mail->applyIfConfigured();

            Mail::raw(
                "Correo de prueba de AlphaRey — la configuración SMTP funciona.\n"
                .'AlphaRey test email — the SMTP configuration works.',
                function ($message) use ($request): void {
                    $message->to($request->validated('to'))
                        ->subject('Prueba de correo / Mail test — AlphaRey');
                },
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('ui.settings.test_failed'));
        }

        return back()->with('success', __('ui.settings.test_sent'));
    }
}
