<?php

namespace App\Http\Controllers;

use App\Enums\ProjectRateType;
use App\Models\Designation;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Per-designation project rates (Feature 2): what the client pays and what we
 * pay the worker, per trade type, per project. One row per (project, designation)
 * — re-posting the same designation updates it. Gated by `projects.edit`.
 */
class ProjectDesignationRateController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.edit');

        $data = $this->validated($request);

        // updateOrCreate on (project, designation): company_id is filled by the
        // BelongsToCompany hook; the match query is company-scoped.
        $rate = ProjectDesignationRate::query()->firstOrNew([
            'project_id' => $project->id,
            'designation_id' => $data['designation_id'],
        ]);
        $rate->fill($data);
        $rate->project_id = $project->id;
        $rate->save();

        return back()->with('success', __('ui.project_rates.saved'));
    }

    public function destroy(Project $project, ProjectDesignationRate $designationRate): RedirectResponse
    {
        Gate::authorize('projects.edit');

        // A child reached through the project must belong to it.
        abort_unless($designationRate->project_id === $project->id, 404);

        $designationRate->delete();

        return back()->with('success', __('ui.project_rates.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'designation_id' => [
                'required', 'integer',
                // Only a group-wide default or this company's own designation.
                Rule::exists('designations', 'id')->where(fn ($q) => $q
                    ->where('active', true)
                    ->where(fn ($w) => $w->whereNull('company_id')
                        ->orWhere('company_id', app(CurrentCompany::class)->id()))),
            ],
            'client_rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'worker_rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'rate_type' => ['required', Rule::enum(ProjectRateType::class)],
        ]);
    }

    /**
     * Group-wide + this company's designations, for the rate + employee dropdowns.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function optionsFor(?int $companyId): array
    {
        return Designation::query()
            ->forCompany($companyId)
            ->where('active', true)
            ->orderBy('sort')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Designation $d) => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }
}
