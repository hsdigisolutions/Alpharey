<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\PunchRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Services\Workers\WorkerAttendanceService;
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
    public function __construct(private readonly WorkerAttendanceService $attendance) {}

    public function home(Request $request): Response
    {
        $employee = $this->resolveEmployee($request);
        $today = $this->attendance->todayFor($employee);

        return Inertia::render('Worker/Home', [
            'worker' => [
                'name' => $employee->full_name,
                'code' => $employee->employee_code,
                'company' => $employee->company?->name,
            ],
            'today' => $this->todayPayload($today),
        ]);
    }

    public function checkIn(PunchRequest $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        $this->attendance->checkIn($employee, $request->location(), $request->file('photo'));

        return back()->with('success', __('ui.worker.checked_in'));
    }

    public function checkOut(PunchRequest $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        $this->attendance->checkOut($employee, $request->location());

        return back()->with('success', __('ui.worker.checked_out'));
    }

    public function absence(Request $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->attendance->reportAbsence($employee, $validated['note']);

        return back()->with('success', __('ui.worker.absence_saved'));
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
            'check_in' => $today->check_in,
            'check_out' => $today->check_out,
            'hours' => $today->check_out !== null ? (float) $today->hours_worked : null,
        ];
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
