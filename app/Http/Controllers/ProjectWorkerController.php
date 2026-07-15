<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
use App\Models\ReportRemark;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Project workers (Screen 09 Tab 2) + immutable notes (Tab 8). Cross-
 * company deployment of workers arrives in Phase 5; this handles own-
 * company worker assignment with per-project rate overrides.
 */
class ProjectWorkerController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.edit');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'wage_type' => ['nullable', 'in:daily,hourly,monthly,per_meter'],
            'project_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        // Employee must belong to the same company (own-company workers only
        // in Phase 3). The global scope confines this lookup to the company.
        $employee = Employee::query()->find($validated['employee_id']);
        abort_if($employee === null, 404);

        $rate = new ProjectEmployeeRate([
            'employee_id' => $employee->id,
            'wage_type' => $validated['wage_type'] ?? $employee->wage_type?->value,
            'project_rate' => isset($validated['project_rate']) ? (string) $validated['project_rate'] : null,
        ]);
        $rate->project_id = $project->id;
        $rate->company_id = $project->company_id;
        $rate->save();

        return back()->with('success', __('ui.projects.worker_added'));
    }

    public function destroy(Project $project, ProjectEmployeeRate $rate): RedirectResponse
    {
        Gate::authorize('projects.edit');

        abort_unless($rate->project_id === $project->id, 404);

        $rate->delete();

        return back()->with('success', __('ui.projects.worker_removed'));
    }

    /**
     * Add an immutable project note (Tab 8). Cannot be edited or deleted
     * once saved (ReportRemark enforces it at the model layer).
     */
    public function storeRemark(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.edit');

        $validated = $request->validate([
            'type' => ['required', 'in:internal,client_call,client_email,meeting,message'],
            'body' => ['required', 'string', 'max:5000'],
            'noted_at' => ['nullable', 'date'],
        ]);

        $remark = new ReportRemark([
            'type' => $validated['type'],
            'body' => $validated['body'],
            'noted_at' => $validated['noted_at'] ?? now(),
        ]);
        $remark->project_id = $project->id;
        $remark->company_id = $project->company_id;
        $remark->user_id = $request->user()?->id;
        $remark->created_at = now();
        $remark->save();

        return back()->with('success', __('ui.projects.note_saved'));
    }

    public function storeAlert(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.edit');

        $validated = $request->validate([
            'alert_type' => ['required', 'in:budget,deadline,progress,custom'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'scheduled_date' => ['nullable', 'date'],
            'email_recipients' => ['nullable', 'string', 'max:1000'],
            'email_cc' => ['nullable', 'string', 'max:1000'],
        ]);

        $project->alerts()->create($validated + ['status' => 'pending']);

        return back()->with('success', __('ui.projects.alert_saved'));
    }
}
