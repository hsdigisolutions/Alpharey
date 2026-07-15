<?php

namespace App\Http\Requests\Clients;

use App\Enums\ClientType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('clients.create');
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
            'vat_number' => ['nullable', 'string', 'max:30'],
            'client_type' => ['required', Rule::enum(ClientType::class)],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:34'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'industry' => ['nullable', 'string', 'max:100'],
            'company_size' => ['nullable', 'string', 'max:50'],
            'preferred_contact' => ['nullable', 'in:email,phone,mobile,other'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
