<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransferEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Transfer moves an employee between companies — an admin-level action.
        return Gate::allows('employees.edit')
            && $user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'to_company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'transfer_date' => ['required', 'date'],
        ];
    }
}
