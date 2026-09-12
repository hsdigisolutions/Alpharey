<?php

namespace App\Services\ProductionTasks;

use App\Models\Attendance;
use App\Models\ProductionTask;
use App\Models\TaskProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The daily-production writer (Phase D). A "Log work" entry splits a total
 * quantity EQUALLY across the workers who were present on the project that day
 * (attendance-constrained), writing one `task_progress` row per worker under a
 * shared `batch_id`. The rounding remainder is given to the last worker so
 * Σ(rows) == the entered total to the cent — no quantity is created or lost.
 * The task's `completed_quantity` is then recomputed = Σ quantity (this service
 * is its only writer, keeping the Phase-B progress bars honest).
 */
class TaskProgressService
{
    /**
     * @param  array{date: string, quantity: numeric, employee_ids: list<int>, notes?: string|null, is_rework?: bool}  $data
     *
     * @throws ValidationException
     */
    public function logWork(ProductionTask $task, array $data, ?UploadedFile $photo = null): void
    {
        // Rework (client rejected the previous work) is a cost/penalty only —
        // server-set here, never mass-assigned. It bills nothing and does not add
        // to completed_quantity (the meters were already produced once).
        $isRework = (bool) ($data['is_rework'] ?? false);

        $employeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'])));

        $this->assertPresentOnProject($task, $data['date'], $employeeIds);

        $total = round((float) $data['quantity'], 2);
        $count = count($employeeIds);
        $split = round($total / $count, 2);
        $batchId = (string) Str::uuid();

        $photoPath = null;
        $photoName = null;
        if ($photo !== null) {
            $photoName = $photo->getClientOriginalName();
            $photoPath = $photo->store("task-progress/{$task->company_id}/{$task->id}", 'local');
        }

        DB::transaction(function () use ($task, $employeeIds, $count, $total, $split, $batchId, $photoPath, $photoName, $data, $isRework): void {
            foreach ($employeeIds as $i => $employeeId) {
                // Last worker carries the rounding remainder so the batch sums exactly.
                $qty = $i === $count - 1
                    ? round($total - $split * ($count - 1), 2)
                    : $split;

                $row = new TaskProgress([
                    'production_task_id' => $task->id,
                    'employee_id' => $employeeId,
                    'date' => $data['date'],
                    'quantity' => $qty,
                    'notes' => $data['notes'] ?? null,
                ]);
                $row->batch_id = $batchId;
                $row->logged_by = Auth::id();
                $row->photo_path = $photoPath;
                $row->photo_name = $photoName;
                $row->is_rework = $isRework; // server-set, not fillable
                // company_id is filled from the active company by BelongsToCompany.
                $row->save();
            }

            $this->recompute($task);
        });
    }

    /**
     * Delete a whole multi-worker entry (all rows of a batch) and its photo,
     * then recompute the task total.
     */
    public function deleteBatch(ProductionTask $task, string $batchId): void
    {
        $rows = TaskProgress::query()
            ->where('production_task_id', $task->id)
            ->where('batch_id', $batchId)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $photoPath = $rows->first()->photo_path;

        DB::transaction(function () use ($task, $rows, $photoPath): void {
            foreach ($rows as $row) {
                $row->delete();
            }
            if ($photoPath !== null && Storage::disk('local')->exists($photoPath)) {
                Storage::disk('local')->delete($photoPath);
            }
            $this->recompute($task);
        });
    }

    /**
     * Delete every proof photo of a task's progress rows from disk. Called
     * BEFORE the task is deleted — the DB cascade removes the task_progress
     * rows but fires no model events, so the files would otherwise orphan.
     */
    public function purgePhotosForTask(ProductionTask $task): void
    {
        $paths = TaskProgress::query()
            ->where('production_task_id', $task->id)
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->unique();

        foreach ($paths as $path) {
            if ($path !== null && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /**
     * Recompute the task's completed_quantity from its progress rows. Direct
     * assignment — completed_quantity is intentionally NOT mass-assignable.
     */
    public function recompute(ProductionTask $task): void
    {
        // Rework quantity is a redo of already-produced work — it must NOT inflate
        // completion past 100%, so completed_quantity counts non-rework rows only.
        $sum = (float) TaskProgress::query()
            ->where('production_task_id', $task->id)
            ->where('is_rework', false)
            ->sum('quantity');

        $task->completed_quantity = (string) round($sum, 2);
        $task->save();

        // A sub-task's numbers roll up into its parent.
        if ($task->parent_task_id !== null && $task->parent !== null) {
            $this->recomputeParent($task->parent);
        }
    }

    /**
     * A parent's planned + completed are the sum of its children's, so its
     * progress% (= completed / planned) equals the children's progress weighted
     * by their quantities. Direct assignment; the parent is never logged
     * against directly.
     */
    public function recomputeParent(ProductionTask $parent): void
    {
        $children = $parent->children()->get();

        $parent->completed_quantity = (string) round((float) $children->sum(fn (ProductionTask $c): float => (float) $c->completed_quantity), 2);
        $parent->planned_quantity = (string) round((float) $children->sum(fn (ProductionTask $c): float => (float) $c->planned_quantity), 2);
        $parent->save();
    }

    /**
     * Every logged worker must have a worked attendance row on THIS project for
     * the given date — production is credited only to people who were on site.
     *
     * @param  list<int>  $employeeIds
     *
     * @throws ValidationException
     */
    private function assertPresentOnProject(ProductionTask $task, string $date, array $employeeIds): void
    {
        if ($employeeIds === []) {
            throw ValidationException::withMessages(['employee_ids' => __('ui.task_progress.no_workers')]);
        }

        // Deployed workers are logged under the HOST project's company_id, so
        // the project filter (not a tenant scope) is the right constraint here.
        $present = Attendance::query()
            ->withoutGlobalScopes()
            ->where('project_id', $task->project_id)
            ->whereDate('date', $date)
            ->whereIn('status', ['present', 'late', 'early_leave'])
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->unique()
            ->all();

        $absent = array_diff($employeeIds, $present);
        if ($absent !== []) {
            throw ValidationException::withMessages([
                'employee_ids' => __('ui.task_progress.not_present'),
            ]);
        }
    }
}
