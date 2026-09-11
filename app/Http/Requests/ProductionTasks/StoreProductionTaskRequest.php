<?php

namespace App\Http\Requests\ProductionTasks;

use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Bulk add: the project task board adds one OR many rows at once, so every
 * create posts a `tasks` array (a single add is an array of one).
 */
class StoreProductionTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production_tasks.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // When present, every task in this submission is a sub-task of it.
            // Ownership + one-level-deep are enforced in the controller.
            'parent_task_id' => ['nullable', 'integer'],
            'tasks' => ['required', 'array', 'min:1', 'max:100'],
            'tasks.*.name' => ['required', 'string', 'max:255'],
            'tasks.*.category' => ['required', Rule::enum(ProductionTaskCategory::class)],
            'tasks.*.house_number' => ['nullable', 'string', 'max:100'],
            'tasks.*.unit' => ['nullable', 'string', 'max:20'],
            // Internal cost rate — never client billing.
            'tasks.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            // Client billing rate (task-based P&L revenue = progress × client_rate).
            'tasks.*.client_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tasks.*.planned_quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            // Weightage is advisory — the project sum need not equal 100.
            'tasks.*.weightage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tasks.*.status' => ['required', Rule::enum(ProductionTaskStatus::class)],
            'tasks.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
