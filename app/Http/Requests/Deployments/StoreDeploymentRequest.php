<?php

namespace App\Http\Requests\Deployments;

use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('deployments.create');
    }

    /**
     * host_company_id is never accepted — it is the acting user's company
     * (deployments are created from the host's own project).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'home_company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'deployment_start' => ['required', 'date'],
            'deployment_end' => ['nullable', 'date', 'after_or_equal:deployment_start'],
            // Only Option A is automated; the form offers A only (B is illegal,
            // C not yet automated) — validated to A here as a hard guard.
            'billing_method' => ['required', Rule::enum(BillingMethod::class), Rule::in([BillingMethod::OptionA->value])],
            'rate_during_deployment' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rate_type' => ['required', Rule::enum(DeploymentRateType::class)],
            'split_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
