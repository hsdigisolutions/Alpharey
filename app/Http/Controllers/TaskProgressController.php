<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductionTasks\StoreTaskProgressRequest;
use App\Models\Attendance;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\TaskProgress;
use App\Services\Audit\AuditLogger;
use App\Services\ProductionTasks\TaskProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Daily production entries against a project task (Phase D). Nested under the
 * project + task; each action re-checks the task belongs to the project (→ 404).
 */
class TaskProgressController extends Controller
{
    public function __construct(private readonly TaskProgressService $service) {}

    /**
     * Workers present (worked attendance) on this project for a given date —
     * the only people production can be credited to. JSON for the log modal.
     */
    public function presentWorkers(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('production_tasks.edit');

        $date = $request->date('date')?->toDateString() ?? now()->toDateString();

        $workers = Attendance::query()
            ->withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->whereDate('date', $date)
            ->whereIn('status', ['present', 'late', 'early_leave'])
            ->with('employee:id,full_name,designation')
            ->get()
            ->unique('employee_id')
            ->map(fn (Attendance $a): array => [
                'id' => $a->employee_id,
                'name' => $a->employee?->full_name,
                'designation' => $a->employee?->designation,
            ])
            ->values()
            ->all();

        return response()->json(['workers' => $workers]);
    }

    public function store(StoreTaskProgressRequest $request, Project $project, ProductionTask $task): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);
        // Production is logged on sub-tasks, never on a parent — a parent's
        // completed rolls up from its children (UI hides the button; enforce it).
        abort_if($task->children()->exists(), 422, 'Log production on the sub-tasks, not the parent task.');

        $this->service->logWork($task, $request->validated(), $request->file('photo'));

        return back()->with('success', __('ui.task_progress.saved'));
    }

    public function destroy(Project $project, ProductionTask $task, string $batch): RedirectResponse
    {
        Gate::authorize('production_tasks.edit');
        abort_unless($task->project_id === $project->id, 404);

        $this->service->deleteBatch($task, $batch);

        return back()->with('success', __('ui.task_progress.deleted'));
    }

    public function photo(TaskProgress $taskProgress): BinaryFileResponse
    {
        Gate::authorize('production_tasks.view');

        abort_unless($taskProgress->photo_path !== null && Storage::disk('local')->exists($taskProgress->photo_path), 404);

        app(AuditLogger::class)->log('viewed', $taskProgress, null, null, 'Task progress photo', 'production_tasks');

        return response()->file(Storage::disk('local')->path($taskProgress->photo_path));
    }
}
