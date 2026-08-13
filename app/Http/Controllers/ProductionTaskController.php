<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductionTasks\StoreProductionTaskRequest;
use App\Http\Requests\ProductionTasks\UpdateProductionTaskRequest;
use App\Models\ProductionTask;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Production tasks — internal planned-vs-actual tracking, nested under a
 * project. Company-owned (the project is already tenant-scoped by route
 * binding; each task re-checks it belongs to the project → 404 otherwise).
 */
class ProductionTaskController extends Controller
{
    public function store(StoreProductionTaskRequest $request, Project $project): RedirectResponse
    {
        foreach ($request->validated()['tasks'] as $data) {
            $task = new ProductionTask($data);
            $task->project_id = $project->id;
            // company_id is filled from the active company by BelongsToCompany.
            $task->save();
        }

        return back()->with('success', __('ui.production_tasks.saved'));
    }

    public function update(UpdateProductionTaskRequest $request, Project $project, ProductionTask $task): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        $task->update($request->validated());

        return back()->with('success', __('ui.production_tasks.saved'));
    }

    public function destroy(Project $project, ProductionTask $task): RedirectResponse
    {
        Gate::authorize('production_tasks.delete');
        abort_unless($task->project_id === $project->id, 404);

        $task->delete();

        return back()->with('success', __('ui.production_tasks.deleted'));
    }
}
