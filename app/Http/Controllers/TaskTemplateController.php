<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductionTasks\StoreTaskTemplateRequest;
use App\Http\Requests\ProductionTasks\UpdateTaskTemplateRequest;
use App\Models\TaskTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Task templates — a company's reusable production-task catalogue. Managed
 * inline from the project Tareas tab ("Templates" modal). Tenant-scoped by
 * `BelongsToCompany`, so route binding 404s another company's row.
 */
class TaskTemplateController extends Controller
{
    public function store(StoreTaskTemplateRequest $request): RedirectResponse
    {
        // company_id is filled from the active company by BelongsToCompany.
        TaskTemplate::query()->create($request->validated());

        return back()->with('success', __('ui.task_templates.saved'));
    }

    public function update(UpdateTaskTemplateRequest $request, TaskTemplate $taskTemplate): RedirectResponse
    {
        $taskTemplate->update($request->validated());

        return back()->with('success', __('ui.task_templates.saved'));
    }

    public function destroy(TaskTemplate $taskTemplate): RedirectResponse
    {
        Gate::authorize('production_tasks.delete');

        $taskTemplate->delete();

        return back()->with('success', __('ui.task_templates.deleted'));
    }
}
