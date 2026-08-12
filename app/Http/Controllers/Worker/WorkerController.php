<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\CheckOutRequest;
use App\Http\Requests\Worker\PunchRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Workers\WorkerAttendanceService;
use App\Services\Workers\WorkerConsentService;
use App\Services\Workers\WorkerDashboardService;
use App\Support\NotificationPresenter;
use App\Support\WorkerPrivacyNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Worker PWA.
 *
 * Everything here is scoped to ONE employee — the one the signed-in worker is
 * linked to — so there is no company selector, no filters, and no id from
 * input. EnsureWorker has already proven the account is a worker WITH an
 * employee record, so resolveEmployee() cannot come back empty.
 */
class WorkerController extends Controller
{
    public function __construct(
        private readonly WorkerAttendanceService $attendance,
        private readonly WorkerDashboardService $dashboard,
        private readonly WorkerConsentService $consents,
    ) {}

    public function home(Request $request): Response
    {
        $employee = $this->resolveEmployee($request);
        $today = $this->attendance->todayFor($employee);

        return Inertia::render('Worker/Home', [
            'worker' => [
                'name' => $employee->full_name,
                'code' => $employee->employee_code,
                'company' => $employee->company?->name,
                'can_use_vehicles' => $employee->can_use_vehicles,
            ],
            'today' => $this->todayPayload($today, $this->fullDayThreshold($employee)),
            // Weekend gating: a Sat/Sun is a rest day unless an admin offer
            // invites this worker (then the offer's project is shown + check-in
            // is allowed). Weekdays are always workable.
            'weekend' => $this->weekendPayload($employee),
            // The current month's calendar + figures for the dashboard below.
            'month' => $this->dashboard->forMonth($employee),
            // Consent state (GDPR). `privacy_acknowledged` false → the app shows
            // the consent screen and blocks check-in (the server refuses too).
            // consent_gps / consent_photo drive whether the app requests a GPS
            // fix / a selfie at all, and power the in-app revoke panel.
            'privacy_acknowledged' => $employee->hasAcknowledgedPrivacyNotice(),
            'consent' => [
                'gps' => $employee->consentGps(),
                'photo' => $employee->consentPhoto(),
                'version' => WorkerPrivacyNotice::currentVersion(),
            ],
            // Worker-direct notifications (advance/expense/leave decided, weekend
            // offer) — the PWA bell. Unread count + the latest 10, normalised.
            // NOTE: NO financial data (advances, deductions, expense amounts) is
            // ever put on the worker payload — workers see attendance only
            // (client rule 2026-08-08, reinforced here). Not merely UI-hidden.
            'notifications' => $this->notificationsPayload($request),
        ]);
    }

    /**
     * The worker's own notifications for the PWA bell (worker-direct types).
     *
     * @return array{unread: int, items: array<int, array<string, mixed>>}
     */
    private function notificationsPayload(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['unread' => 0, 'items' => []];
        }

        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(10)->get()
                ->map(fn (DatabaseNotification $n) => NotificationPresenter::present($n))
                ->all(),
        ];
    }

    public function checkIn(PunchRequest $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);
        $this->requirePrivacyNotice($employee);

        // Consent gates the extras: no GPS consent → no location captured & no
        // GPS-missing alert; no selfie consent → no photo required or stored.
        $gpsConsent = $employee->consentGps();
        $location = $gpsConsent ? $request->location() : self::NO_LOCATION;
        $photo = $employee->consentPhoto() ? $request->file('photo') : null;

        $attendance = $this->attendance->checkIn($employee, $location, $photo, $gpsConsent);

        $redirect = redirect()->route('worker.home')->with('success', __('ui.worker.checked_in'));

        // Poor GPS accuracy (IP-based / weak signal) can't anchor the day — warn
        // the worker; the punch still stands (GPS is evidence, not a gate).
        if ($attendance->check_in_accuracy !== null
            && (float) $attendance->check_in_accuracy > WorkerAttendanceService::LOCATION_ACCURACY_LIMIT) {
            $redirect->with('warning', __('ui.worker.gps_weak'));
        }

        return $redirect;
    }

    public function checkOut(CheckOutRequest $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);
        $this->requirePrivacyNotice($employee);

        $location = $employee->consentGps() ? $request->location() : self::NO_LOCATION;

        $attendance = $this->attendance->checkOut($employee, $location, $request->file('work_attachment'));

        $redirect = redirect()->route('worker.home')->with('success', __('ui.worker.checked_out'));

        if ($attendance->location_mismatch) {
            $redirect->with('warning', __('ui.worker.location_far'));
        }

        return $redirect;
    }

    /** No-GPS location payload (worker withheld/revoked GPS consent). */
    private const NO_LOCATION = ['lat' => null, 'lng' => null, 'accuracy' => null, 'denied' => false];

    /**
     * Record the worker's consent (GDPR art. 7 & 13). The attendance time record
     * is mandatory (a legal obligation, RD-ley 8/2019) so `consent_attendance`
     * must be accepted; GPS and the selfie are OPTIONAL, each its own checkbox.
     * The full evidence context (IP, user-agent, timestamp, exact text, version,
     * language) is captured by WorkerConsentService.
     */
    public function acknowledgePrivacy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'consent_attendance' => ['accepted'],
            'consent_gps' => ['nullable', 'boolean'],
            'consent_photo' => ['nullable', 'boolean'],
        ]);

        $employee = $this->resolveEmployee($request);
        $language = $request->user()?->locale === 'en' ? 'en' : 'es';

        $this->consents->record(
            $employee,
            $request,
            (bool) ($validated['consent_gps'] ?? false),
            (bool) ($validated['consent_photo'] ?? false),
            $language,
        );

        return redirect()->route('worker.home');
    }

    /**
     * The worker changes / revokes their optional GPS & selfie consent from the
     * PWA. Attendance consent is untouched (the app keeps working); a new consent
     * row records the change with fresh evidence.
     */
    public function updateConsent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'consent_gps' => ['required', 'boolean'],
            'consent_photo' => ['required', 'boolean'],
        ]);

        $employee = $this->resolveEmployee($request);

        $this->consents->updatePreferences(
            $employee,
            $request,
            (bool) $validated['consent_gps'],
            (bool) $validated['consent_photo'],
            'Preferences updated by worker',
        );

        return redirect()->route('worker.home')->with('success', __('ui.worker.privacy.prefs_saved'));
    }

    public function absence(Request $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->attendance->reportAbsence($employee, $validated['note']);

        return redirect()->route('worker.home')->with('success', __('ui.worker.absence_saved'));
    }

    /**
     * Weekend state for the home screen. On a weekday: workable, nothing to say.
     * On a weekend: a rest day unless an offer invites this worker, in which case
     * the offer's project + rate are surfaced and check-in is unlocked.
     *
     * @return array{is_weekend: bool, rest_day: bool, offer: array{project: string|null, rate_type: string}|null}
     */
    private function weekendPayload(Employee $employee): array
    {
        $isWeekend = Carbon::now()->isWeekend();

        if (! $isWeekend) {
            return ['is_weekend' => false, 'rest_day' => false, 'offer' => null];
        }

        $offer = $this->attendance->weekendOfferFor($employee);

        if ($offer === null) {
            return ['is_weekend' => true, 'rest_day' => true, 'offer' => null];
        }

        // The project is tenant-scoped; a worker has no session, so drop the scope.
        $project = $offer->project_id !== null
            ? Project::query()->withoutGlobalScope(CompanyScope::class)->where('id', $offer->project_id)->value('name')
            : null;

        return [
            'is_weekend' => true,
            'rest_day' => false,
            'offer' => ['project' => $project, 'rate_type' => $offer->weekend_rate_type->value],
        ];
    }

    /**
     * The company's full-day threshold (hours) — the target that turns the
     * check-out button from amber (day not yet complete) to green.
     */
    private function fullDayThreshold(Employee $employee): float
    {
        return app(AttendanceService::class)
            ->dayTypeThresholds((int) $employee->company_id)['full'];
    }

    /**
     * Today's status for the home screen — enough for the check-in confirmation
     * card and the check-out day summary. The project name is loaded with the
     * tenant scope DROPPED: a worker has no CRM session, so a scoped read comes
     * back null (the same bug the dashboard calendar hit). The pay figure is the
     * worker's OWN, shown only once the day is closed.
     *
     * @return array<string, mixed>
     */
    private function todayPayload(?Attendance $today, float $fullDayThreshold): array
    {
        if ($today === null) {
            return ['state' => 'none'];
        }

        if ($today->status->value === 'absent') {
            return ['state' => 'absent', 'note' => $today->worker_note];
        }

        $project = $today->project_id !== null
            ? Project::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('id', $today->project_id)
                ->value('name')
            : null;

        return [
            'state' => $today->check_out !== null ? 'checked_out' : 'checked_in',
            'attendance_id' => $today->id,
            'check_in' => $today->check_in,
            'check_out' => $today->check_out,
            // Absolute check-in instant (ISO, with offset) so the live "time
            // worked" counter is computed from real elapsed time — never from the
            // "HH:mm" label parsed in the phone's own timezone (that was off by
            // the phone↔server offset).
            'check_in_at' => $today->check_in_at?->toIso8601String(),
            'hours' => $today->check_out !== null ? (float) $today->hours_worked : null,
            // The full-day target (hours) so the check-out button can turn green
            // once the worker has put in a full day, amber before that.
            'full_day_threshold' => $fullDayThreshold,
            'project' => $project,
            // Evidence the GPS fix landed — drives the "ubicación capturada"
            // line and the amber "no capturada" warning on the confirmation.
            'location_captured' => $today->check_in_lat !== null && $today->check_in_lng !== null,
            // NO money: a worker never sees wage amounts (client rule 2026-08-08).
        ];
    }

    /**
     * Server-side half of the notice gate: a punch captures a GPS fix (and, on
     * check-in, a selfie), so it must not proceed until the worker has been
     * shown and acknowledged the current notice. The screen already blocks it,
     * but UI hiding is never the only control (dev-skill Rule 2) — a crafted
     * POST is refused with a 403.
     */
    protected function requirePrivacyNotice(Employee $employee): void
    {
        abort_unless($employee->hasAcknowledgedPrivacyNotice(), 403);
    }

    /**
     * The employee behind the signed-in worker. Tenant scope dropped (a worker
     * is not "browsing a company", they ARE one employee), reached only through
     * their own unique user_id, SoftDeletes kept so a removed employee's login
     * stops punching.
     *
     * @throws ValidationException
     */
    protected function resolveEmployee(Request $request): Employee
    {
        return Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('company:id,name')
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
