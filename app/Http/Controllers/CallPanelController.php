<?php

namespace App\Http\Controllers;

use App\Enums\CallOutcome;
use App\Models\Employee;
use App\Models\EmployeeCallLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Screen 13 — Call Panel. Left column: who to call. Right column: the log for
 * the selected worker. Top: a single date filter (month navigator or custom
 * range) that drives the month OVERVIEW — four clearly-separated categories:
 * calls made · connected · not connected · follow-ups.
 *
 * Builds on employee_call_logs (Phase 2, Screen 06 Tab 6). Each call now records
 * an OUTCOME (connected / no answer), which is what makes the connected-vs-not
 * separation possible.
 */
class CallPanelController extends Controller
{
    /**
     * "Not contacted this week" (the left-list tab) is measured against the
     * start of the current week, not a rolling 7 days: the client thinks in
     * working weeks, and a Monday-morning list that still counts last Tuesday's
     * call as "this week" would be wrong on the one day the list matters most.
     */
    private function weekStart(): Carbon
    {
        return now()->startOfWeek();
    }

    public function index(Request $request): Response
    {
        Gate::authorize('call_panel.view');

        $selectedId = $request->integer('employee');
        [$from, $to, $mode, $month] = $this->resolveOverviewRange($request);

        return Inertia::render('CallPanel/Index', [
            'employees' => $this->employeeList($request),
            'filters' => (object) [
                'search' => $request->query('search'),
                'tab' => $request->query('tab'),
                'employee' => $request->query('employee'),
                // The single overview range, echoed so the UI reflects the
                // effective selection (default = the current month).
                'range' => $mode,
                'month' => $month,
                'from' => $from,
                'to' => $to,
            ],
            // The selected worker's FULL history (its own date range was removed
            // — one date filter on the screen now, the overview one).
            'selected' => $selectedId > 0 ? $this->selected($selectedId) : null,
            'overview' => $this->overview($from, $to, $mode),
            'callOutcomes' => CallOutcome::options(),
            'can' => [
                'create' => Gate::allows('call_panel.create'),
                'edit' => Gate::allows('call_panel.edit'),
                'delete' => Gate::allows('call_panel.delete'),
            ],
        ]);
    }

    /**
     * Resolve the ONE overview date range from the request. A custom from/to
     * wins; then an explicit all-time; otherwise a month (default: this month).
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?string} [from, to, mode, month]
     */
    private function resolveOverviewRange(Request $request): array
    {
        $from = $this->validDate($request->query('from'));
        $to = $this->validDate($request->query('to'));
        if ($from !== null || $to !== null) {
            return [$from, $to, 'custom', null];
        }

        if ($request->query('range') === 'all') {
            return [null, null, 'all', null];
        }

        // Month mode (YYYY-MM), defaulting to the current month.
        $raw = $request->query('month');
        $base = null;
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            try {
                $base = Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth();
            } catch (\Throwable) {
                $base = null;
            }
        }
        $base ??= now()->startOfMonth();

        return [
            $base->copy()->startOfMonth()->toDateString(),
            $base->copy()->endOfMonth()->toDateString(),
            'month',
            $base->format('Y-m'),
        ];
    }

    /**
     * A user-supplied Y-m-d date, or null when absent/malformed — the same
     * validation convention the Reports / Today's Report date filters use.
     */
    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('call_panel.create');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'called_at' => ['nullable', 'date'],
            // Did the worker actually pick up? Defaults to connected (the common
            // case) when the form omits it.
            'call_outcome' => ['nullable', Rule::enum(CallOutcome::class)],
            'remarks' => ['required', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
            // video/webm is included because a browser MediaRecorder webm blob is
            // sniffed by finfo as video/webm (the Matroska container), not audio/webm.
            'voice_note' => ['nullable', 'file', 'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-m4a', 'max:10240'],
            'voice_note_label' => ['nullable', 'string', 'max:255'],
            'attachment' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp4,audio/ogg,audio/webm,video/mp4,image/jpeg,image/png,image/webp,application/pdf', 'max:102400'],
            'attachment_label' => ['nullable', 'string', 'max:255'],
        ]);

        // A foreign employee does not exist under the global scope (Rule 1).
        $employee = Employee::query()->find($validated['employee_id']);

        if ($employee === null) {
            return back()->withErrors(['employee_id' => __('ui.calls.employee_not_found')]);
        }

        $call = new EmployeeCallLog([
            'called_at' => $validated['called_at'] ?? now(),
            'remarks' => $validated['remarks'],
            'call_outcome' => $validated['call_outcome'] ?? CallOutcome::Connected->value,
            'follow_up_date' => $validated['follow_up_date'] ?? null,
        ]);
        $call->employee_id = $employee->id;
        $call->company_id = $employee->company_id;
        $call->called_by = $request->user()?->id;

        if ($request->hasFile('voice_note')) {
            $file = $request->file('voice_note');
            $ext = $file->extension() ?: 'webm';
            $path = $file->storeAs(
                "call-voice-notes/{$employee->company_id}/{$employee->id}",
                Str::random(32).'.'.$ext,
                'local',
            );
            $call->voice_note_path = $path;
            $call->voice_note_label = $validated['voice_note_label'] ?: 'Voice Note';
        }

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $ext = $file->extension() ?: 'bin';
            $path = $file->storeAs(
                "call-attachments/{$employee->company_id}/{$employee->id}",
                Str::random(32).'.'.$ext,
                'local',
            );
            $call->attachment_path = $path;
            $call->attachment_original_name = $file->getClientOriginalName();
            $call->attachment_label = $validated['attachment_label'] ?: $file->getClientOriginalName();
        }

        $call->save();

        return back()->with('success', __('ui.calls.saved'));
    }

    /**
     * Serve a voice note or attachment from private storage — gated + audited
     * (Rule 10: every private-file download is audited).
     */
    public function download(Request $request, EmployeeCallLog $call): StreamedResponse
    {
        Gate::authorize('call_panel.view');

        $type = $request->query('type', 'attachment');

        $path = $type === 'voice' ? $call->voice_note_path : $call->attachment_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $filename = $type === 'voice'
            ? ($call->voice_note_label ?? 'voice-note')
            : ($call->attachment_label ?? $call->attachment_original_name ?? 'attachment');

        app(AuditLogger::class)->log('viewed', $call, ['download_type' => $type]);

        return Storage::disk('local')->download($path, $filename);
    }

    /**
     * Rename the voice-note or attachment label (user-friendly display name).
     */
    public function rename(Request $request, EmployeeCallLog $call): RedirectResponse
    {
        Gate::authorize('call_panel.edit');

        $validated = $request->validate([
            'type' => ['required', Rule::in(['voice', 'attachment'])],
            'label' => ['required', 'string', 'max:255'],
        ]);

        if ($validated['type'] === 'voice') {
            $call->voice_note_label = $validated['label'];
        } else {
            $call->attachment_label = $validated['label'];
        }
        $call->save();

        return back()->with('success', __('ui.calls.renamed'));
    }

    /**
     * The left column. Each row carries its own last-contact and follow-up
     * facts so the indicator is computed once, server-side. This "who to call"
     * triage is intentionally ABSOLUTE (not bound by the overview range) — it is
     * "who to call now", not a historical slice.
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
            // Eager-load the call logs (newest first) so each row derives its
            // last call + soonest follow-up in memory — no per-employee queries.
            ->with(['company:id,name', 'callLogs' => fn ($q) => $q->orderByDesc('called_at')])
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
        // Derived from the eager-loaded logs (ordered newest-first) — no query.
        $lastCall = $e->callLogs->first();

        // The soonest outstanding follow-up, not the latest call's one: an
        // older call can hold the follow-up that is actually due.
        $followUp = $e->callLogs
            ->whereNotNull('follow_up_date')
            ->min('follow_up_date');

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
     * The right column: the worker's card plus their FULL call history (no date
     * range — the one date filter on the screen is the overview one).
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
                    'outcome' => $c->call_outcome?->value,
                    'remarks' => $c->remarks,
                    'follow_up_date' => $c->follow_up_date?->toDateString(),
                    'has_voice_note' => $c->voice_note_path !== null,
                    'voice_note_label' => $c->voice_note_label,
                    'has_attachment' => $c->attachment_path !== null,
                    'attachment_label' => $c->attachment_label,
                    'attachment_original_name' => $c->attachment_original_name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The month OVERVIEW — four clearly-separated categories over the selected
     * range (company-scoped). Connected / Not connected are PER-PERSON: a worker
     * reached at least once in the range is Connected; one called but never
     * reached is Not connected. Follow-ups are the people with a follow-up dated
     * in the range (an orthogonal "needs a callback" list).
     *
     * @return array<string, mixed>
     */
    private function overview(?string $from, ?string $to, string $mode): array
    {
        // Calls made in the range, with the worker + caller for the lists.
        $calls = EmployeeCallLog::query()
            ->when($from !== null, fn ($q) => $q->whereDate('called_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('called_at', '<=', $to))
            ->with(['employee:id,full_name,designation', 'caller:id,name'])
            ->orderByDesc('called_at')
            ->get();

        $callsMade = $calls->map(fn (EmployeeCallLog $c): array => [
            'id' => $c->id,
            'employee_id' => $c->employee_id,
            'name' => $c->employee?->full_name,
            'designation' => $c->employee?->designation,
            'called_at' => $c->called_at->toDateTimeString(),
            'called_by' => $c->caller?->name,
            'outcome' => $c->call_outcome?->value,
            'remarks' => Str::limit((string) $c->remarks, 80),
            'follow_up_date' => $c->follow_up_date?->toDateString(),
        ])->all();

        // Per-person connected / not-connected split.
        $connected = [];
        $notConnected = [];
        foreach ($calls->groupBy('employee_id') as $group) {
            /** @var Collection<int, EmployeeCallLog> $group */
            $emp = $group->first()?->employee;
            $reached = $group->contains(fn (EmployeeCallLog $c): bool => $c->call_outcome === CallOutcome::Connected);
            $row = [
                'id' => $group->first()?->employee_id,
                'name' => $emp?->full_name,
                'designation' => $emp?->designation,
                'calls' => $group->count(),
                'last_called' => $group->max('called_at')?->toDateTimeString(),
            ];
            if ($reached) {
                $connected[] = $row;
            } else {
                $notConnected[] = $row;
            }
        }

        // Follow-ups dated within the range, one row per worker (soonest first).
        $followUpCalls = EmployeeCallLog::query()
            ->whereNotNull('follow_up_date')
            ->when($from !== null, fn ($q) => $q->whereDate('follow_up_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('follow_up_date', '<=', $to))
            ->with('employee:id,full_name,designation')
            ->get();

        $followUps = $followUpCalls->groupBy('employee_id')->map(function (Collection $group): array {
            /** @var Collection<int, EmployeeCallLog> $group */
            $soonest = $group->min('follow_up_date');
            $date = $soonest !== null ? Carbon::parse($soonest) : null;

            return [
                'id' => $group->first()?->employee_id,
                'name' => $group->first()?->employee?->full_name,
                'designation' => $group->first()?->employee?->designation,
                'follow_up_date' => $date?->toDateString(),
                'indicator' => $this->indicator($date),
            ];
        })->sortBy('follow_up_date')->values()->all();

        // A friendly label for the period header.
        $label = match ($mode) {
            'all' => __('ui.calls.range_all'),
            'custom' => trim(($from ?? '…').' – '.($to ?? '…')),
            default => $from !== null ? Carbon::parse($from)->translatedFormat('F Y') : '',
        };

        return [
            'period_label' => $label,
            'calls_made' => ['count' => $calls->count(), 'calls' => $callsMade],
            'connected' => ['count' => count($connected), 'people' => $connected],
            'not_connected' => ['count' => count($notConnected), 'people' => $notConnected],
            'follow_ups' => ['count' => count($followUps), 'people' => $followUps],
        ];
    }
}
