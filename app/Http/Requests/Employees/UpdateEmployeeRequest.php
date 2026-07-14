<?php

namespace App\Http\Requests\Employees;

use Illuminate\Support\Facades\Gate;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function authorize(): bool
    {
        return Gate::allows('employees.edit');
    }
}
