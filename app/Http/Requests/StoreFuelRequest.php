<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreFuelRequest extends FormRequest
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
            'fuel_date' => ['required', 'date'],
            'litres' => ['required', 'numeric', 'min:0.01'],
            'cost_per_litre' => ['required', 'numeric', 'min:0'],
            'total_cost' => ['required', 'numeric', 'min:0'],
            'mileage_at_fill' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
