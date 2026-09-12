<?php

namespace App\Http\Requests\Projects;

use App\Enums\BillingType;
use App\Enums\MaterialSupply;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\VatRate;
use App\Rules\OwnCompanyEmployee;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('projects.create');
    }

    /**
     * company_id is never accepted (tenancy Rule 1); code is generated
     * server-side. VAT is the optional VatRate dropdown (DECISIONS.md).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            // Optional, admin-editable code. Blank on create → auto-generated
            // (Project::nextCode). Unique per company; ignores self on update.
            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('projects', 'code')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($project?->id),
            ],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'project_type' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'priority' => ['required', Rule::enum(ProjectPriority::class)],
            'billing_type' => ['nullable', Rule::enum(BillingType::class)],
            // Descriptive material-supply label only — no calculation impact.
            'material_supply' => ['nullable', Rule::enum(MaterialSupply::class)],
            'vat_rate' => ['nullable', Rule::enum(VatRate::class)],
            // The project's people are now links to EMPLOYEE records of the
            // acting company (the legacy free-text columns stay untouched as a
            // display fallback). OwnCompanyEmployee blocks a cross-company id.
            'site_manager_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'foreman_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'safety_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'coordinator_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Site location — for worker check-in distance verification. Radius
            // is the on-site geofence (metres), capped at 2 km for a large site.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius' => ['nullable', 'integer', 'min:50', 'max:2000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'estimated_meters' => ['nullable', 'numeric', 'min:0'],
            // Profitability: what we bill the CLIENT (revenue side) + the cost of
            // an outsourced project. Never sensitive pay data.
            'client_hour_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'client_meter_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'outsource_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'outsourced' => ['boolean'],
            'google_drive_link' => ['nullable', 'string', 'max:255'],
            'document_url' => ['nullable', 'string', 'max:255'],
            'forma_de_pago' => ['nullable', 'string', 'max:100'],
            'fecha_de_cobro' => ['nullable', 'string', 'max:100'],
            'color_code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
