<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports the CURRENT FILTERED VIEW (§10) — the query is built by
 * EmployeeQueryFilter in the controller. Wage columns are included only
 * when the exporting user may see them; NIF/IBAN are never exported.
 *
 * @implements WithMapping<Employee>
 */
class EmployeesExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Employee>  $employees
     */
    public function __construct(
        private Collection $employees,
        private bool $withWages,
    ) {}

    /**
     * @return Collection<int, Employee>
     */
    public function collection(): Collection
    {
        return $this->employees;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = [
            'Código / Code', 'Nombre / Name', 'Empresa / Company',
            'Departamento / Department', 'Puesto / Designation',
            'Ciudad / City', 'Móvil / Mobile', 'Email',
            'Tipo salario / Wage type', 'Alta / Joined', 'Activo / Active',
        ];

        if ($this->withWages) {
            $headings[] = 'Tarifa / Wage rate';
            $headings[] = 'Salario base / Base salary';
            $headings[] = 'Comisión % / Commission %';
        }

        return $headings;
    }

    /**
     * @param  Employee  $employee
     * @return list<mixed>
     */
    public function map($employee): array
    {
        $row = [
            $employee->employee_code,
            $employee->full_name,
            $employee->company?->name,
            $employee->department,
            $employee->designation,
            $employee->city,
            $employee->mobile,
            $employee->email,
            $employee->wage_type?->value,
            $employee->joining_date?->toDateString(),
            $employee->active ? 'Sí / Yes' : 'No',
        ];

        if ($this->withWages) {
            $row[] = $employee->getAttribute('wage_rate');
            $row[] = $employee->getAttribute('base_salary');
            $row[] = $employee->commission_percent;
        }

        return $row;
    }
}
