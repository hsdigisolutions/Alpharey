<?php

namespace App\Http\Requests\Deployments;

use App\Enums\DeploymentRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Edit an ACTIVE deployment. Only the safe-to-overwrite fields are accepted:
 * employee, rate structure (type / value / split %), end date, notes. The
 * home company, host company, project and start date are fixed once created
 * (changing them would be a different posting) — they are never accepted here.
 * The controller applies the active-only, attendance-safety and overlap guards.
 */
class UpdateDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('deployments.edit');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'rate_type' => ['required', Rule::enum(DeploymentRateType::class)],
            'rate_during_deployment' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'split_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deployment_end' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
