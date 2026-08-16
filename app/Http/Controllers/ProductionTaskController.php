<?php

namespace App\Http\Controllers;

use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
use App\Exports\ProductionTasksExport;
use App\Http\Requests\ProductionTasks\StoreProductionTaskRequest;
use App\Http\Requests\ProductionTasks\UpdateProductionTaskRequest;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\ProductionTasks\TaskProgressService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Production tasks — internal planned-vs-actual tracking. The standalone screen
 * lists every task across all of the company's projects; tasks are also created
 * and logged from a project's Tareas tab. Company-owned (BelongsToCompany); each
 * nested action re-checks the task belongs to its project (→ 404 otherwise).
 */
class ProductionTaskController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('production_tasks.view');

        $tasks = $this->filteredQuery($request)->get()
            ->map(fn (ProductionTask $t): array => $this->row($t))
            ->values()->all();

        return Inertia::render('ProductionTasks/Index', [
            'tasks' => $tasks,
            'filters' => (object) $request->only(['search', 'project_id', 'category', 'status']),
            'filterOptions' => [
                'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'code']),
                'categories' => array_map(fn (ProductionTaskCategory $c) => $c->value, ProductionTaskCategory::cases()),
                'statuses' => array_map(fn (ProductionTaskStatus $s) => $s->value, ProductionTaskStatus::cases()),
            ],
            'can' => [
                'create' => Gate::allows('production_tasks.create'),
                'edit' => Gate::allows('production_tasks.edit'),
                'delete' => Gate::allows('production_tasks.delete'),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('production_tasks.view');
        app(AuditLogger::class)->log('exported', new ProductionTask, null, null, 'Production tasks Excel', 'production_tasks');

        return Excel::download(new ProductionTasksExport($this->filteredQuery($request)), 'production-tasks.xlsx');
    }

    public function exportPdf(Request $request): \Illuminate\Http\Response
    {
        Gate::authorize('production_tasks.view');
        app(AuditLogger::class)->log('exported', new ProductionTask, null, null, 'Production tasks PDF', 'production_tasks');

        $rows = $this->filteredQuery($request)->get()->map(fn (ProductionTask $t): array => $this->row($t))->all();

        return Pdf::loadView('exports.production-tasks-pdf', ['rows' => $rows])->download('production-tasks.pdf');
    }

    public function store(StoreProductionTaskRequest $request, Project $project, TaskProgressService $progress): RedirectResponse
    {
        $validated = $request->validated();
        $parent = $this->resolveParent($validated['parent_task_id'] ?? null, $project);

        foreach ($validated['tasks'] as $data) {
            $task = new ProductionTask($data);
            $task->project_id = $project->id;
            // Server-set — a sub-task links to its parent (not mass-assignable).
            $task->parent_task_id = $parent?->id;
            // company_id is filled from the active company by BelongsToCompany.
            $task->save();
        }

        // New sub-tasks change the parent's rolled-up planned/completed.
        if ($parent !== null) {
            $progress->recomputeParent($parent);
        }

        return back()->with('success', __('ui.production_tasks.saved'));
    }

    public function update(UpdateProductionTaskRequest $request, Project $project, ProductionTask $task, TaskProgressService $progress): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        $task->update($request->validated());

        // Keep the roll-up honest: a sub-task edit re-rolls its parent; editing a
        // parent that has children re-derives its planned/completed from them.
        if ($task->parent_task_id !== null && $task->parent !== null) {
            $progress->recomputeParent($task->parent);
        } elseif ($task->children()->exists()) {
            $progress->recomputeParent($task);
        }

        return back()->with('success', __('ui.production_tasks.saved'));
    }

    public function destroy(Project $project, ProductionTask $task, TaskProgressService $progress): RedirectResponse
    {
        Gate::authorize('production_tasks.delete');
        abort_unless($task->project_id === $project->id, 404);

        $parent = $task->parent;

        // Clean proof photos for the task AND any sub-tasks before the DB cascade
        // removes them + their progress rows (cascade fires no model events, so
        // files would otherwise orphan).
        foreach ($task->children()->get() as $child) {
            $progress->purgePhotosForTask($child);
        }
        $progress->purgePhotosForTask($task);
        $task->delete(); // cascades to sub-tasks + their progress

        // Deleting a sub-task changes what remains under its parent.
        if ($parent !== null) {
            $progress->recomputeParent($parent);
        }

        return back()->with('success', __('ui.production_tasks.deleted'));
    }

    /**
     * Resolve + validate an optional parent for new sub-tasks: it must belong to
     * this project and be TOP-LEVEL (one level deep only — no sub-sub-tasks).
     */
    private function resolveParent(?int $parentId, Project $project): ?ProductionTask
    {
        if ($parentId === null) {
            return null;
        }

        $parent = ProductionTask::query()->where('project_id', $project->id)->find($parentId);
        abort_if($parent === null, 404);
        // A sub-task cannot itself be a parent (max one level deep).
        abort_if($parent->parent_task_id !== null, 422, 'A sub-task cannot have sub-tasks.');
        // A parent's numbers roll up from its children, so a task that already
        // carries its OWN logged production cannot become a parent — that
        // production would be silently excluded from the roll-up.
        abort_if($parent->progress()->exists(), 422, 'This task already has logged production; it cannot become a parent.');

        return $parent;
    }

    /**
     * The filtered task query shared by the list and both exports (so
     * "export the filtered view" is literal). Tenant-scoped by the model.
     *
     * @return Builder<ProductionTask>
     */
    private function filteredQuery(Request $request): Builder
    {
        return ProductionTask::query()
            ->with('project:id,name,code')
            ->when($request->filled('search'), fn (Builder $q) => $q->where('name', 'like', '%'.$request->string('search')->value().'%'))
            ->when($request->filled('project_id'), fn (Builder $q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->string('category')->value()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->value()))
            ->orderBy('project_id')->orderBy('category')->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProductionTask $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'project' => $t->project === null ? null : ['id' => $t->project->id, 'name' => $t->project->name, 'code' => $t->project->code],
            'category' => $t->category->value,
            'house_number' => $t->house_number,
            'unit' => $t->unit,
            'planned_quantity' => (float) $t->planned_quantity,
            'completed_quantity' => (float) $t->completed_quantity,
            'weightage' => (float) $t->weightage,
            'progress' => $t->progressPercent(),
            'health' => $t->health(),
            'status' => $t->status->value,
        ];
    }
}
