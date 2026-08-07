<?php

namespace App\Http\Requests\Subcontractors;

use App\Enums\SubcontractorPaymentStatus;
use App\Rules\OwnCompanyEmployee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreSubcontractorWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('subcontractors.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_our_employee' => ['boolean'],
            // When they are one of ours, the linked employee must belong to us.
            'employee_id' => ['nullable', 'integer', 'required_if:is_our_employee,true', new OwnCompanyEmployee],
            'days_worked' => ['required', 'numeric', 'min:0', 'max:100000'],
            'agreed_rate' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'payment_status' => ['required', Rule::enum(SubcontractorPaymentStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
