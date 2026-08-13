<?php

namespace App\Http\Controllers;

use App\Enums\MeasurementStatus;
use App\Enums\MeasurementType;
use App\Exports\MeasurementsExport;
use App\Models\Employee;
use App\Models\Measurement;
use App\Models\Project;
use App\Rules\OwnCompanyEmployee;
use App\Rules\OwnCompanyProject;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screen 24 — Measurements. Company-owned. A three-state review workflow
 * (pending → approved → rejected, with a reason): APPROVED measurements are the
 * per-meter billing / P&L income source. `approved` (the legacy boolean) is
 * kept in sync = status===approved so every existing reader keeps working.
 */
class MeasurementController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('measurements.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $measurements = $this->filteredQuery($request)
            ->with(['project:id,name', 'employee:id,full_name'])
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Measurement $m): array => $this->row($m));

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
            'filters' => $request->only(['project_id', 'employee_id', 'status', 'date_from', 'date_to', 'per_page']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            // A2 — the standalone page can now set the employee.
            'employees' => $this->employees(),
            'types' => array_map(fn (MeasurementType $t) => $t->value, MeasurementType::cases()),
            'can' => [
                'create' => Gate::allows('measurements.create'),
                'edit' => Gate::allows('measurements.edit'),
                'delete' => Gate::allows('measurements.delete'),
                'approve' => Gate::allows('measurements.approve'),
                'export' => Gate::allows('measurements.export'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('measurements.create');

        $measurement = new Measurement($this->validated($request));
        $measurement->company_id = app(CurrentCompany::class)->id();
        $measurement->save();

        return back()->with('success', __('ui.measurements.saved'));
    }

    public function update(Request $request, Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.edit');

        // Approved rows are locked; pending and REJECTED rows are editable —
        // editing a rejected measurement resubmits it (back to pending).
        abort_if($measurement->status === MeasurementStatus::Approved, 422, 'Approved measurements cannot be edited.');

        $measurement->fill($this->validated($request));
        if ($measurement->status === MeasurementStatus::Rejected) {
            $measurement->status = MeasurementStatus::Pending;
            $measurement->rejection_reason = null;
            $measurement->approved = false;
        }
        $measurement->save();

        return back()->with('success', __('ui.measurements.saved'));
    }

    public function destroy(Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.delete');

        abort_if($measurement->status === MeasurementStatus::Approved, 422, 'Approved measurements cannot be deleted.');

        $measurement->delete();

        return back()->with('success', __('ui.measurements.deleted'));
    }

    /** Approve — the measurement now feeds per-meter billing / P&L income. */
    public function approve(Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.approve');

        // status / approved / audit stamps are set only through these gated
        // actions — never mass-assignable.
        $measurement->status = MeasurementStatus::Approved;
        $measurement->approved = true;
        $measurement->approved_by = Auth::id();
        $measurement->approved_at = now();
        $measurement->rejection_reason = null;
        $measurement->save();

        return back()->with('success', __('ui.measurements.approved'));
    }

    /** Reject with a reason so the worker knows what to fix and resubmit. */
    public function reject(Request $request, Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.approve');

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $measurement->status = MeasurementStatus::Rejected;
        $measurement->approved = false;
        $measurement->approved_by = null;
        $measurement->approved_at = null;
        $measurement->rejection_reason = $validated['rejection_reason'];
        $measurement->save();

        return back()->with('success', __('ui.measurements.rejected'));
    }

    /** Reopen — back to pending (un-approve, or clear a rejection). */
    public function reset(Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.approve');

        $measurement->status = MeasurementStatus::Pending;
        $measurement->approved = false;
        $measurement->approved_by = null;
        $measurement->approved_at = null;
        $measurement->rejection_reason = null;
        $measurement->save();

        return back()->with('success', __('ui.measurements.reset'));
    }

    /** The CURRENT FILTERED VIEW as a spreadsheet — same query as the screen. */
    public function export(Request $request, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('measurements.export');

        $audit->log('exported', new Measurement, null, null, 'Measurements Excel', 'measurements');

        return Excel::download(new MeasurementsExport($this->filteredQuery($request)), 'mediciones.xlsx');
    }

    /** The CURRENT FILTERED VIEW as a PDF. */
    public function exportPdf(Request $request, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('measurements.export');

        $rows = $this->filteredQuery($request)->with(['project:id,name', 'employee:id,full_name'])->get();
        $audit->log('exported', new Measurement, null, null, 'Measurements PDF', 'measurements');

        return Pdf::loadView('exports.measurements-pdf', [
            'measurements' => $rows,
            'generated_at' => now()->toDayDateTimeString(),
        ])->download('mediciones.pdf');
    }

    /**
     * The shared filtered query for the screen AND the exports (so "export the
     * filtered view" is literal). Company scope is applied by the global scope.
     *
     * @return Builder<Measurement>
     */
    private function filteredQuery(Request $request): Builder
    {
        return Measurement::query()
            ->when($request->filled('project_id'), fn (Builder $q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('employee_id'), fn (Builder $q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when(
                in_array($request->string('status')->value(), ['pending', 'approved', 'rejected'], true),
                fn (Builder $q) => $q->where('status', $request->string('status')->value()),
            )
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('date', '<=', $request->string('date_to')))
            ->orderByDesc('date');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Measurement $m): array
    {
        return [
            'id' => $m->id,
            'date' => $m->date->toDateString(),
            'project' => $m->project?->name,
            'employee' => $m->employee?->full_name,
            'employee_id' => $m->employee_id,
            'quantity' => (float) $m->quantity,
            'unit' => $m->unit,
            'measurement_type' => $m->measurement_type->value,
            'status' => $m->status->value,
            'approved' => $m->approved,
            'rejection_reason' => $m->rejection_reason,
            'notes' => $m->notes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(): array
    {
        return Employee::query()->where('active', true)->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Employee $e): array => ['id' => $e->id, 'name' => $e->full_name])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            // Own-company only: an approved measurement for another company's
            // per-meter worker would land in THAT company's payroll.
            'project_id' => ['required', 'integer', new OwnCompanyProject],
            'employee_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'measurement_type' => ['required', Rule::enum(MeasurementType::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
