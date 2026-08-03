<?php

namespace App\Http\Controllers\Worker;

use App\Enums\AdvanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\PunchRequest;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\WorkerExpense;
use App\Services\Workers\WorkerAttendanceService;
use App\Services\Workers\WorkerDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Approved advances that have not yet been deducted from payroll. Amounts
     * are encrypted; we decrypt here for the worker's OWN data (no gate needed
     * — this is the worker reading their own pay data).
     *
     * @return list<array{amount: float, reason: string|null}>
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
                'amount' => (float) $a->getAttribute('amount'),
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
     * Today's status for the home screen — enough for the button to know what
     * it should offer next. No pay figure here; that lives on the dashboard
     * behind its own shaping (Phase D).
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

        return [
            'state' => $today->check_out !== null ? 'checked_out' : 'checked_in',
            'attendance_id' => $today->id,
            'check_in' => $today->check_in,
            'check_out' => $today->check_out,
            'hours' => $today->check_out !== null ? (float) $today->hours_worked : null,
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
