<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OvertimePolicyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestMailRequest;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\UpdateMailSettingsRequest;
use App\Models\OvertimePolicy;
use App\Services\Attendance\AttendanceService;
use App\Services\Notifications\NotificationRules;
use App\Services\Settings\MailSettings;
use App\Services\Settings\SettingsService;
use App\Services\System\SystemHealth;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
            // Auto day-type thresholds (hours), per the active company.
            'dayTypeThresholds' => app(AttendanceService::class)
                ->dayTypeThresholds(app(CurrentCompany::class)->id() ?? 0),
            // Notification matrix + system health are brand-level → Super Admin only
            'notificationMatrix' => $isSuperAdmin ? $rules->matrix() : null,
            'systemHealth' => $isSuperAdmin ? $health->check() : null,
        ]);
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
        ]);

        $settings->set("attendance.full_day_threshold.{$companyId}", (float) $validated['full_day_threshold']);
        $settings->set("attendance.half_day_threshold.{$companyId}", (float) $validated['half_day_threshold']);

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
