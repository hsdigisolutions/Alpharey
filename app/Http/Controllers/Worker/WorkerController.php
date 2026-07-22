<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Worker PWA home screen.
 *
 * Everything here is scoped to ONE employee — the one the signed-in worker is
 * linked to — so there is no company selector, no filters and no ids from
 * input. `EnsureWorker` has already proven the account is a worker with an
 * employee record, so resolveEmployee() cannot come back empty.
 *
 * Phase A ships the shell and the resolved identity; the check-in/out
 * capture, the calendar and the month figures land in Phases C and D.
 */
class WorkerController extends Controller
{
    public function home(Request $request): Response
    {
        $employee = $this->resolveEmployee($request);

        return Inertia::render('Worker/Home', [
            'worker' => [
                'name' => $employee->full_name,
                'code' => $employee->employee_code,
                'company' => $employee->company?->name,
            ],
        ]);
    }

    /**
     * The employee behind the signed-in worker.
     *
     * The tenant scope is dropped deliberately: a worker is not "browsing a
     * company", they ARE one employee, and the row is reached only through
     * their own user_id — which is unique, so this can never resolve to
     * somebody else. SoftDeletes stays in force, so a removed employee's login
     * stops working rather than silently continuing to punch.
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
