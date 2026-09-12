<?php

namespace App\Http\Requests\ProductionTasks;

use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Edit a single production task. `completed_quantity` is absent — it is
 * recomputed from daily progress (Phase D), never set by hand.
 */
class UpdateProductionTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production_tasks.edit');
    }

    /**
     * `unit_price` is the INTERNAL cost and the DB column is NOT NULL (default
     * 0) — a cleared field arrives as null (empty-string→null middleware) and
     * would 500 on save (the reported white screen). Blank internal cost means
     * 0. `client_rate` is deliberately left nullable — null = "not billable".
     */
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('unit_price'), [null, ''], true)) {
            $this->merge(['unit_price' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(ProductionTaskCategory::class)],
            'house_number' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'client_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'planned_quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'weightage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::enum(ProductionTaskStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
