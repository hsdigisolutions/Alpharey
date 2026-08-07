<?php

namespace App\Http\Requests\Subcontractors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSubcontractorPaymentRequest extends FormRequest
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
            'payment_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
