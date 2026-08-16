<?php

namespace App\Services\ProductionTasks;

use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\TaskProgress;
use Illuminate\Support\Carbon;

/**
 * Phase E — production reporting for a project's Tareas tab. Read-only analytics
 * over production_tasks + task_progress:
 *   E1 planned-vs-actual per task (+ estimated finish),
 *   E2 per-worker productivity,
 *   E3 a daily production trend series per category (for the chart).
 * All quantities are internal tracking — never client billing.
 */
class ProductionReportService
{
    /**
     * E1 — planned vs actual per task. Estimated finish = today + ceil(remaining
     * ÷ average daily rate over the last 7 days); null when there is no recent
     * production to extrapolate from (or the task is already complete).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function plannedVsActual(Project $project, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $windowStart = $today->copy()->subDays(6)->toDateString(); // 7-day window incl. today

        $tasks = ProductionTask::query()
            ->where('project_id', $project->id)
            ->orderBy('category')->orderBy('name')
            ->get();

        // One grouped query for every task's 7-day production, keyed by task id
        // (was a per-task sum() inside the loop — a classic N+1).
        $recentByTask = TaskProgress::query()
            ->whereIn('production_task_id', $tasks->pluck('id'))
            ->whereDate('date', '>=', $windowStart)
            ->whereDate('date', '<=', $today->toDateString())
            ->groupBy('production_task_id')
            ->selectRaw('production_task_id, SUM(quantity) as total')
            ->pluck('total', 'production_task_id');

        $rows = [];
        $sumPlanned = 0.0;
        $sumDone = 0.0;

        foreach ($tasks as $task) {
            $planned = (float) $task->planned_quantity;
            $done = (float) $task->completed_quantity;
            $remaining = max(0.0, round($planned - $done, 2));

            // Average daily rate over the last 7 calendar days.
            $recent = (float) ($recentByTask[$task->id] ?? 0);
            $avgDaily = round($recent / 7, 2);

            $estFinish = null;
            if ($remaining <= 0.0) {
                $estFinish = 'done';
            } elseif ($avgDaily > 0) {
                $daysLeft = (int) ceil($remaining / $avgDaily);
                $estFinish = $today->copy()->addDays($daysLeft)->toDateString();
            }

            $rows[] = [
                'id' => $task->id,
                'name' => $task->name,
                'category' => $task->category->value,
                'unit' => $task->unit,
                'planned' => $planned,
                'done' => $done,
                'remaining' => $remaining,
                'progress' => $task->progressPercent(),
                'health' => $task->health(),
                'avg_daily' => $avgDaily,
                'est_finish' => $estFinish,
            ];

            $sumPlanned += $planned;
            $sumDone += $done;
        }

        return [
            'rows' => $rows,
            'totals' => [
                'planned' => round($sumPlanned, 2),
                'done' => round($sumDone, 2),
                'remaining' => round(max(0.0, $sumPlanned - $sumDone), 2),
            ],
        ];
    }

    /**
     * E2 — per-worker productivity. Total quantity each worker has been credited
     * on this project, with a 7-day window figure. Newest-heaviest first.
     *
     * @return list<array<string, mixed>>
     */
    public function perWorker(Project $project, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $windowStart = $today->copy()->subDays(6)->toDateString();

        $rows = TaskProgress::query()
            ->where('company_id', $project->company_id)
            ->whereIn('production_task_id', ProductionTask::query()->where('project_id', $project->id)->select('id'))
            // withTrashed so a later soft-deleted worker's history still names them.
            ->with(['employee' => fn ($q) => $q->select('id', 'full_name')->withTrashed()])
            ->get()
            ->groupBy('employee_id')
            ->map(function ($group) use ($windowStart, $today): array {
                $first = $group->first();
                $last7 = $group
                    ->filter(fn (TaskProgress $r) => $r->date->toDateString() >= $windowStart && $r->date->toDateString() <= $today->toDateString())
                    ->sum(fn (TaskProgress $r) => (float) $r->quantity);

                return [
                    'employee' => $first->employee->full_name ?? '—',
                    'total' => round((float) $group->sum(fn (TaskProgress $r) => (float) $r->quantity), 2),
                    'last7' => round((float) $last7, 2),
                    'entries' => $group->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return $rows;
    }

    /**
     * E3 — daily production trend, one series per category (units may differ per
     * category, so each category is its own line rather than a summed total).
     *
     * @return array{labels: list<string>, series: list<array{category: string, data: list<float>}>}
     */
    public function trend(Project $project): array
    {
        $progress = TaskProgress::query()
            ->where('company_id', $project->company_id)
            ->whereIn('production_task_id', ProductionTask::query()->where('project_id', $project->id)->select('id'))
            ->with('task:id,category')
            ->get();

        if ($progress->isEmpty()) {
            return ['labels' => [], 'series' => []];
        }

        $dates = $progress->map(fn (TaskProgress $r) => $r->date->toDateString())->unique()->sort()->values()->all();

        $series = [];
        foreach ($progress->groupBy(fn (TaskProgress $r) => $r->task?->category->value ?? 'other') as $category => $rows) {
            $byDate = $rows->groupBy(fn (TaskProgress $r) => $r->date->toDateString());
            $data = [];
            foreach ($dates as $date) {
                $data[] = round((float) ($byDate->get($date)?->sum(fn (TaskProgress $r) => (float) $r->quantity) ?? 0), 2);
            }
            $series[] = ['category' => $category, 'data' => $data];
        }

        return ['labels' => $dates, 'series' => $series];
    }
}
