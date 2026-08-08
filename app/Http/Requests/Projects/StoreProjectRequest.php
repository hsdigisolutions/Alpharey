<?php

namespace App\Http\Requests\Projects;

use App\Enums\BillingType;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\VatRate;
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
        return [
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'project_type' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'priority' => ['required', Rule::enum(ProjectPriority::class)],
            'billing_type' => ['nullable', Rule::enum(BillingType::class)],
            'vat_rate' => ['nullable', Rule::enum(VatRate::class)],
            'jefe_de_obra' => ['nullable', 'string', 'max:255'],
            'jefe_phone' => ['nullable', 'string', 'max:30'],
            'jefe_email' => ['nullable', 'email', 'max:255'],
            'encargado' => ['nullable', 'string', 'max:255'],
            'seguridad' => ['nullable', 'string', 'max:255'],
            'coordinator' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
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
