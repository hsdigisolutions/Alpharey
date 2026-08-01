<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreDailyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('vehicles.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_date' => ['required', 'date'],
            'employee_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->filled('employee_id')) {
                return;
            }
            if (! Employee::query()->whereKey($this->integer('employee_id'))->exists()) {
                $v->errors()->add('employee_id', __('ui.vehicles.employee_not_found'));
            }
        });
    }
}
