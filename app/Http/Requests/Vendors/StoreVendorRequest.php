<?php

namespace App\Http\Requests\Vendors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('vendors.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'bank_account' => ['nullable', 'string', 'max:34'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
