<?php

namespace App\Http\Controllers;

use App\Enums\MeasurementType;
use App\Models\Measurement;
use App\Models\Project;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 24 — Measurements. Company-owned. Approve/reject workflow;
 * approved measurements feed project billing (Phase 6).
 */
class MeasurementController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('measurements.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $measurements = Measurement::query()
            ->with(['project:id,name', 'employee:id,full_name'])
            ->when($request->filled('project_id'), fn (Builder $q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->string('approval')->value() === 'approved', fn (Builder $q) => $q->where('approved', true))
            ->when($request->string('approval')->value() === 'pending', fn (Builder $q) => $q->where('approved', false))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('date', '<=', $request->string('date_to')))
            ->orderByDesc('date')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Measurement $m): array => [
                'id' => $m->id,
                'date' => $m->date->toDateString(),
                'project' => $m->project?->name,
                'employee' => $m->employee?->full_name,
                'quantity' => (float) $m->quantity,
                'unit' => $m->unit,
                'measurement_type' => $m->measurement_type->value,
                'approved' => $m->approved,
                'notes' => $m->notes,
            ]);

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
            'filters' => $request->only(['project_id', 'approval', 'date_from', 'date_to', 'per_page']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'types' => array_map(fn (MeasurementType $t) => $t->value, MeasurementType::cases()),
            'can' => [
                'create' => Gate::allows('measurements.create'),
                'edit' => Gate::allows('measurements.edit'),
                'delete' => Gate::allows('measurements.delete'),
                'approve' => Gate::allows('measurements.approve'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('measurements.create');

        $validated = $this->validated($request);

        $measurement = new Measurement($validated);
        $measurement->company_id = app(CurrentCompany::class)->id();
        $measurement->save();

        return back()->with('success', __('ui.measurements.saved'));
    }

    public function update(Request $request, Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.edit');

        $measurement->update($this->validated($request));

        return back()->with('success', __('ui.measurements.saved'));
    }

    public function destroy(Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.delete');

        $measurement->delete();

        return back()->with('success', __('ui.measurements.deleted'));
    }

    /**
     * Approve / reject (un-approve) — feeds project billing when approved.
     */
    public function approve(Request $request, Measurement $measurement): RedirectResponse
    {
        Gate::authorize('measurements.approve');

        $approve = $request->boolean('approved', true);

        // approved/approved_by/approved_at are NOT mass-assignable (they are
        // set only through this permission-gated action) — assign directly.
        $measurement->approved = $approve;
        $measurement->approved_by = $approve ? $request->user()?->id : null;
        $measurement->approved_at = $approve ? now() : null;
        $measurement->save();

        return back()->with('success', __('ui.measurements.'.($approve ? 'approved' : 'rejected')));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'measurement_type' => ['required', Rule::enum(MeasurementType::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
