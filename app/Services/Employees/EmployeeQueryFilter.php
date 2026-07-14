<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Screen 05 filter set, shared by the list page and the Excel/PDF
 * exports so "export the current filtered view" (§10) is literal.
 */
class EmployeeQueryFilter
{
    /**
     * @return Builder<Employee>
     */
    public function apply(Request $request): Builder
    {
        return Employee::query()
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->value();
                $q->where(function (Builder $q) use ($term): void {
                    $q->where('full_name', 'like', "%{$term}%")
                        ->orWhere('employee_code', 'like', "%{$term}%")
                        // Encrypted NIF is searched via its blind index
                        ->orWhere('nif_hash', Employee::hashNif($term));
                });
            })
            ->when($request->string('status')->value() === 'active', fn (Builder $q) => $q->where('active', true))
            ->when($request->string('status')->value() === 'inactive', fn (Builder $q) => $q->where('active', false))
            ->when($request->filled('department'), fn (Builder $q) => $q->where('department', $request->string('department')))
            ->when($request->filled('designation'), fn (Builder $q) => $q->where('designation', $request->string('designation')))
            ->when($request->filled('wage_type'), fn (Builder $q) => $q->where('wage_type', $request->string('wage_type')));
    }
}
