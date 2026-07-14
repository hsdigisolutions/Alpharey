<?php

namespace App\Imports;

use App\Enums\WageType;
use App\Services\Employees\EmployeeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk employee creation from the downloadable template (§10). Rows are
 * validated individually; failures are collected (row number + reason),
 * never silently skipped, and valid rows still import.
 */
class EmployeesImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    /** @var list<array{row: int, errors: string}> */
    public array $failures = [];

    public function __construct(private EmployeeService $service) {}

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $data = [
                'full_name' => trim((string) ($row['nombre'] ?? $row['full_name'] ?? '')),
                'nif' => $row['nif'] ?? null,
                'email' => $row['email'] ?? null,
                'mobile' => $row['movil'] ?? $row['mobile'] ?? null,
                'city' => $row['ciudad'] ?? $row['city'] ?? null,
                'department' => $row['departamento'] ?? $row['department'] ?? null,
                'designation' => $row['puesto'] ?? $row['designation'] ?? null,
                'joining_date' => $row['fecha_alta'] ?? $row['joining_date'] ?? null,
                'wage_type' => $row['tipo_salario'] ?? $row['wage_type'] ?? null,
                'wage_rate' => $row['tarifa'] ?? $row['wage_rate'] ?? null,
                'base_salary' => $row['salario_base'] ?? $row['base_salary'] ?? null,
                'active' => true,
            ];

            $validator = Validator::make($data, [
                'full_name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email'],
                'wage_type' => ['nullable', Rule::enum(WageType::class)],
                'wage_rate' => ['nullable', 'numeric', 'min:0'],
                'base_salary' => ['nullable', 'numeric', 'min:0'],
                'joining_date' => ['nullable', 'date'],
            ]);

            if ($validator->fails()) {
                $this->failures[] = [
                    'row' => $index + 2, // heading row offset
                    'errors' => implode(' ', $validator->errors()->all()),
                ];

                continue;
            }

            $this->service->create($validator->validated());
            $this->imported++;
        }
    }
}
