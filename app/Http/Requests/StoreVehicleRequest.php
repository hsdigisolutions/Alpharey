<?php

namespace App\Http\Requests;

use App\Enums\FuelType;
use App\Enums\VehicleOwnership;
use App\Models\Employee;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->routeIs('vehicles.store')
            ? Gate::allows('vehicles.create')
            : Gate::allows('vehicles.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $vehicle = $this->route('vehicle');

        return [
            // Unique within the company, not the group — two companies may
            // legitimately never share a plate, but the group can.
            'plate_number' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicles', 'plate_number')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($vehicle?->id),
            ],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'ownership' => ['required', Rule::enum(VehicleOwnership::class)],
            'assigned_employee_id' => ['nullable', 'integer'],
            'active' => ['boolean'],
            'fuel_type' => ['nullable', Rule::enum(FuelType::class)],
            'color' => ['nullable', 'string', 'max:40'],
            'vin_number' => ['nullable', 'string', 'max:40'],
            'insurance_policy_number' => ['nullable', 'string', 'max:60'],
            'insurance_expiry_date' => ['nullable', 'date'],
            'ita_expiry_date' => ['nullable', 'date'],
            'purchase_date' => ['nullable', 'date'],
            'current_mileage' => ['nullable', 'integer', 'min:0'],
            'last_oil_change_mileage' => ['nullable', 'integer', 'min:0'],
            'last_oil_change_date' => ['nullable', 'date'],
            'oil_change_interval_km' => ['nullable', 'integer', 'min:0'],
            'next_service_date' => ['nullable', 'date'],
            'last_tyre_change_date' => ['nullable', 'date'],
            'last_tyre_change_mileage' => ['nullable', 'integer', 'min:0'],
            'maintenance_notes' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('assigned_employee_id')) {
                return;
            }

            // The global scope makes a foreign employee simply not exist.
            $exists = Employee::query()->whereKey($this->integer('assigned_employee_id'))->exists();

            if (! $exists) {
                $validator->errors()->add('assigned_employee_id', __('ui.vehicles.employee_not_found'));
            }
        });
    }
}
