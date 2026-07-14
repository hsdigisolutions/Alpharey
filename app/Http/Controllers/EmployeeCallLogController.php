<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeCallLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Screen 06 Tab 6 — Llamadas (the full Call Panel is Phase 7).
 */
class EmployeeCallLogController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('call_panel.create');

        $validated = $request->validate([
            'called_at' => ['nullable', 'date'],
            'remarks' => ['required', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        $call = new EmployeeCallLog([
            'called_at' => $validated['called_at'] ?? now(),
            'remarks' => $validated['remarks'],
            'follow_up_date' => $validated['follow_up_date'] ?? null,
        ]);
        $call->employee_id = $employee->id;
        $call->company_id = $employee->company_id;
        $call->called_by = $request->user()?->id;
        $call->save();

        return back()->with('success', __('ui.employees.call_saved'));
    }
}
