<?php

namespace App\Http\Controllers\Worker;

use App\Enums\AdvanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\PunchRequest;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\WorkerExpense;
use App\Services\Workers\WorkerAttendanceService;
use App\Services\Workers\WorkerDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'today' => $this->todayPayload($today),
            // Weekend gating: a Sat/Sun is a rest day unless an admin offer
            // invites this worker (then the offer's project is shown + check-in
            // is allowed). Weekdays are always workable.
            'weekend' => $this->weekendPayload($employee),
            // The current month's calendar + figures for the dashboard below.
            'month' => $this->dashboard->forMonth($employee),
            // Whether the worker still has to be shown the geolocation + selfie
            // notice before any punch. When false the app blocks check-in
            // behind the notice screen (the server refuses too — below).
            'privacy_acknowledged' => $employee->hasAcknowledgedPrivacyNotice(),
            // Feature 3 — pending advance deductions visible on the dashboard.
            'pending_advances' => $this->pendingAdvances($employee),
            // Feature 2 — recent expense submissions (last 5) so the worker can
            // see the status of what they sent.
            'recent_expenses' => $this->recentExpenses($employee),
        ]);
    }

    public function checkIn(PunchRequest $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);
        $this->requirePrivacyNotice($employee);

        $this->attendance->checkIn($employee, $request->location(), $request->file('photo'));

        return redirect()->route('worker.home')->with('success', __('ui.worker.checked_in'));
    }

    public function checkOut(PunchRequest $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);
        $this->requirePrivacyNotice($employee);

        $this->attendance->checkOut($employee, $request->location());

        return redirect()->route('worker.home')->with('success', __('ui.worker.checked_out'));
    }

    /**
     * Record that the worker read the geolocation + selfie notice. The screen
     * blocks check-in until this is done; this is what unblocks it. Idempotent
     * — a second acknowledgement just refreshes the timestamp/version.
     */
    public function acknowledgePrivacy(Request $request): RedirectResponse
    {
        $this->resolveEmployee($request)->acknowledgePrivacyNotice();

        return redirect()->route('worker.home');
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
     * Approved advances not yet deducted from payroll — surfaced so the worker
     * knows a deduction is coming, but WITHOUT the euro amount: workers never see
     * money amounts (client rule 2026-08-08). Only the month + reason.
     *
     * @return list<array{reason: string|null, payroll_month: string|null}>
     */
    private function pendingAdvances(Employee $employee): array
    {
        return Advance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->where('status', AdvanceStatus::Approved->value)
            ->latest('request_date')
            ->get()
            ->map(fn (Advance $a) => [
                'reason' => $a->reason,
                'payroll_month' => $a->payroll_month,
            ])
            ->all();
    }

    /**
     * The worker's last 5 expense submissions with their current status.
     *
     * @return list<array{date: string, amount: float, category: string, status: string}>
     */
    private function recentExpenses(Employee $employee): array
    {
        return WorkerExpense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (WorkerExpense $e) => [
                'date' => $e->date->toDateString(),
                'amount' => (float) $e->amount,
                'category' => $e->category,
                'status' => $e->status->value,
            ])
            ->all();
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
     * Today's status for the home screen — enough for the check-in confirmation
     * card and the check-out day summary. The project name is loaded with the
     * tenant scope DROPPED: a worker has no CRM session, so a scoped read comes
     * back null (the same bug the dashboard calendar hit). The pay figure is the
     * worker's OWN, shown only once the day is closed.
     *
     * @return array<string, mixed>
     */
    private function todayPayload(?Attendance $today): array
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
