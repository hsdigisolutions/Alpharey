<?php

namespace App\Http\Requests\ProductionTasks;

use App\Rules\OwnCompanyEmployee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Logging daily production against an existing task edits its completion, so it
 * is gated by `production_tasks.edit`. `quantity` is the TOTAL to split across
 * the selected workers (the service does the equal split). The photo is proof
 * of work; it is stored on the private disk by the service.
 */
class StoreTaskProgressRequest extends FormRequest
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
            'date' => ['required', 'date', 'before_or_equal:today'],
            // Total quantity produced that day — split equally across workers.
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'employee_ids.*' => ['integer', new OwnCompanyEmployee],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }
}
