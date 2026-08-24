<?php

namespace App\Http\Requests\Expenses;

use App\Enums\BearableBy;
use App\Enums\VehicleExpenseType;
use App\Rules\OwnCompanyEmployee;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Direct vehicle expense entry (Part D) — fine / maintenance / fuel created from
 * the Expenses tab. Goes through the same two-gate approval flow. vehicle_id is
 * validated to the acting company; the driver / bearer fields are per type.
 */
class StoreVehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('expenses.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'vehicle_expense_type' => ['required', Rule::enum(VehicleExpenseType::class)],
            'vehicle_id' => [
                'required', 'integer',
                Rule::exists('vehicles', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'employee_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            // Maintenance vendor (optional; free text goes into notes instead).
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            // Company (default) or employee-borne (deducted from the driver).
            'bearable_by' => ['nullable', Rule::in([BearableBy::Company->value, BearableBy::Employee->value])],
            'reference' => ['nullable', 'string', 'max:100'],
            'litres' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
