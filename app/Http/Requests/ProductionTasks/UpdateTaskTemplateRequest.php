<?php

namespace App\Http\Requests\ProductionTasks;

use App\Enums\ProductionTaskCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTaskTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production_tasks.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(ProductionTaskCategory::class)],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'planned_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'weightage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['boolean'],
        ];
    }
}
