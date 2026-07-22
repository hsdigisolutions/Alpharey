<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use App\Services\Workers\WorkerAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Granting and revoking an employee's access to the mobile PWA.
 *
 * Gated on `employees.edit` — deciding who may clock in on a phone is
 * workforce administration, and anyone trusted to edit an employee record is
 * trusted with this. The employee is resolved by route binding, so the tenant
 * scope has already proven it belongs to the acting company.
 */
class WorkerAccessController extends Controller
{
    public function __construct(private readonly WorkerAccountService $accounts) {}

    public function store(Request $request, Employee $employee, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('employees.edit');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user = $this->accounts->grant($employee, $validated['email'], $validated['password']);

        // Auditable covers the User row itself; this records the DECISION —
        // "who gave this worker a phone login, and when".
        $audit->log('worker_access_granted', $employee, null, null, $user->email, 'employees');

        return back()->with('success', __('ui.worker_access.granted'));
    }

    public function destroy(Employee $employee, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('employees.edit');

        $audit->log('worker_access_revoked', $employee, null, null, $employee->user?->email, 'employees');

        $this->accounts->revoke($employee);

        return back()->with('success', __('ui.worker_access.revoked'));
    }
}
