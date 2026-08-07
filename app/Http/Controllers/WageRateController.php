<?php

namespace App\Http\Controllers;

use App\Http\Requests\Employees\StoreWageRateRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Services\Employees\WageRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Employee wage history — the "Nueva Tarifa" flow on the employee detail page.
 * Route-model binding scopes both the employee and the rate to the acting
 * company, so another company's records resolve to a 404, never a 403.
 */
class WageRateController extends Controller
{
    public function store(StoreWageRateRequest $request, Employee $employee, WageRateService $service): RedirectResponse
    {
        $data = $request->validated();

        // A back-dated rate reprices UNPAID attendance from that date. Require
        // an explicit confirmation before doing so (edge case 2).
        $isPast = $data['effective_from'] < now()->toDateString();
        $confirmed = (bool) ($data['confirm_recalculate'] ?? false);

        if ($isPast && ! $confirmed) {
            $affected = Attendance::query()->withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->where('date', '>=', $data['effective_from'])
                ->exists();

            if ($affected) {
                throw ValidationException::withMessages([
                    'confirm_recalculate' => __('ui.wage_rates.confirm_recalc'),
                ]);
            }
        }

        $service->createRate($employee, [
            'effective_from' => $data['effective_from'],
            'wage_type' => $data['wage_type'],
            'rate' => $data['rate'],
            'reason' => $data['reason'] ?? null,
        ]);

        return back()->with('success', __('ui.wage_rates.saved'));
    }

    public function destroy(Employee $employee, EmployeeWageRate $wageRate, WageRateService $service): RedirectResponse
    {
        Gate::authorize('employees.edit');

        abort_unless($wageRate->employee_id === $employee->id, 404);

        $service->deleteRate($wageRate);

        return back()->with('success', __('ui.wage_rates.deleted'));
    }
}
