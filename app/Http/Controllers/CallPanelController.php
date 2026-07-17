<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeCallLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 13 — Call Panel. Two columns: who to call on the left, the log for
 * the selected worker on the right.
 *
 * Builds on employee_call_logs (Phase 2, Screen 06 Tab 6) — the same rows, a
 * different surface. The panel adds the follow-up triage the tab does not do.
 */
class CallPanelController extends Controller
{
    /**
     * "Not contacted this week" is measured against the start of the current
     * week, not a rolling 7 days: the client thinks in working weeks, and a
     * Monday-morning list that still counts last Tuesday's call as "this week"
     * would be wrong on the one day the list matters most.
     */
    private function weekStart(): Carbon
    {
        return now()->startOfWeek();
    }

    public function index(Request $request): Response
    {
        Gate::authorize('call_panel.view');

        $selectedId = $request->integer('employee');

        return Inertia::render('CallPanel/Index', [
            'employees' => $this->employeeList($request),
            'filters' => $request->only(['search', 'tab', 'employee']),
            'selected' => $selectedId > 0 ? $this->selected($selectedId) : null,
            'stats' => $this->stats(),
            'can' => [
                'create' => Gate::allows('call_panel.create'),
                'edit' => Gate::allows('call_panel.edit'),
                'delete' => Gate::allows('call_panel.delete'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('call_panel.create');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'called_at' => ['nullable', 'date'],
            'remarks' => ['required', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        // A foreign employee does not exist under the global scope (Rule 1).
        $employee = Employee::query()->find($validated['employee_id']);

        if ($employee === null) {
            return back()->withErrors(['employee_id' => __('ui.calls.employee_not_found')]);
        }

        $call = new EmployeeCallLog([
            'called_at' => $validated['called_at'] ?? now(),
            'remarks' => $validated['remarks'],
            'follow_up_date' => $validated['follow_up_date'] ?? null,
        ]);
        $call->employee_id = $employee->id;
        $call->company_id = $employee->company_id;
        $call->called_by = $request->user()?->id;
        $call->save();

        return back()->with('success', __('ui.calls.saved'));
    }

    /**
     * The left column. Each row carries its own last-contact and follow-up
     * facts so the indicator is computed once, server-side.
     *
     * @return array<int, array<string, mixed>>
     */
    private function employeeList(Request $request): array
    {
        $tab = $request->string('tab')->value() ?: 'all';

        $employees = Employee::query()
            ->where('active', true)
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('search')->value().'%';
                $q->where(fn (Builder $w) => $w->where('full_name', 'like', $term)
                    ->orWhere('mobile', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->with('company:id,name')
            ->orderBy('full_name')
            ->get();

        $rows = $employees->map(fn (Employee $e): array => $this->employeeRow($e))->values();

        return match ($tab) {
            'pending' => $rows->filter(fn (array $r): bool => $r['follow_up_date'] !== null)->values()->all(),
            'not_contacted' => $rows->filter(fn (array $r): bool => $r['not_contacted_this_week'])->values()->all(),
            default => $rows->all(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeRow(Employee $e): array
    {
        $lastCall = EmployeeCallLog::query()
            ->where('employee_id', $e->id)
            ->orderByDesc('called_at')
            ->first();

        // The soonest outstanding follow-up, not the latest call's one: an
        // older call can hold the follow-up that is actually due.
        $followUp = EmployeeCallLog::query()
            ->where('employee_id', $e->id)
            ->whereNotNull('follow_up_date')
            ->orderBy('follow_up_date')
            ->value('follow_up_date');

        $followUpDate = $followUp !== null ? Carbon::parse($followUp) : null;

        return [
            'id' => $e->id,
            'name' => $e->full_name,
            'company' => $e->company?->name,
            'designation' => $e->designation,
            'last_contacted' => $lastCall?->called_at->toDateTimeString(),
            'follow_up_date' => $followUpDate?->toDateString(),
            'indicator' => $this->indicator($followUpDate),
            'not_contacted_this_week' => $lastCall === null
                || $lastCall->called_at->lt($this->weekStart()),
        ];
    }

    /**
     * Red = the follow-up is overdue · amber = it is due today · green =
     * nothing outstanding, or it is still in the future.
     */
    private function indicator(?Carbon $followUp): string
    {
        if ($followUp === null) {
            return 'green';
        }

        $today = now()->startOfDay();

        if ($followUp->startOfDay()->lt($today)) {
            return 'red';
        }

        return $followUp->startOfDay()->eq($today) ? 'amber' : 'green';
    }

    /**
     * The right column: the worker's card plus their full call history.
     *
     * @return array<string, mixed>|null
     */
    private function selected(int $employeeId): ?array
    {
        $employee = Employee::query()->with('company:id,name')->find($employeeId);

        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'mobile' => $employee->mobile,
            'phone' => $employee->phone,
            'company' => $employee->company?->name,
            'designation' => $employee->designation,
            'calls' => EmployeeCallLog::query()
                ->where('employee_id', $employee->id)
                ->with('caller:id,name')
                ->orderByDesc('called_at')
                ->get()
                ->map(fn (EmployeeCallLog $c): array => [
                    'id' => $c->id,
                    'called_at' => $c->called_at->toDateTimeString(),
                    'called_by' => $c->caller?->name,
                    'remarks' => $c->remarks,
                    'follow_up_date' => $c->follow_up_date?->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The top stats bar.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $contactedThisWeek = EmployeeCallLog::query()
            ->where('called_at', '>=', $this->weekStart())
            ->distinct()
            ->pluck('employee_id');

        return [
            'calls_today' => EmployeeCallLog::query()
                ->whereDate('called_at', now()->toDateString())
                ->count(),
            'pending_follow_ups' => EmployeeCallLog::query()
                ->whereNotNull('follow_up_date')
                ->whereDate('follow_up_date', '<=', now()->toDateString())
                ->count(),
            'not_contacted_this_week' => Employee::query()
                ->where('active', true)
                ->whereNotIn('id', $contactedThisWeek)
                ->count(),
        ];
    }
}
