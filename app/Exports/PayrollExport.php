<?php

namespace App\Exports;

use App\Models\Payroll;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Payroll month export (Screen 12).
 *
 * This sheet is nothing but pay figures, so the controller gates the whole
 * export on `payroll.view` before constructing it — there is no "without
 * wages" variant the way EmployeesExport has one.
 *
 * @implements WithMapping<Payroll>
 */
class PayrollExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Payroll>  $payrolls
     */
    public function __construct(private Collection $payrolls) {}

    /**
     * @return Collection<int, Payroll>
     */
    public function collection(): Collection
    {
        return $this->payrolls;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Mes / Month',
            'Empleado / Employee',
            'Empresa / Company',
            'Días / Days',
            'Horas / Hours',
            'Horas extra / Overtime hours',
            'Tipo salario / Wage type',
            'Salario base / Base salary',
            'Días trabajados / Days amount',
            'Horas trabajadas / Hours amount',
            'Horas extra / Overtime pay',
            'Reembolsos / Reimbursements',
            'Gastos de obra / Project expenses',
            'Bruto / Gross',
            'Anticipos / Advances',
            'Otras deducciones / Other deductions',
            'Añadidos / Manual additions',
            'Neto / Net',
            'Estado / Status',
            'Forma de pago / Payment method',
            'Pagada / Paid at',
            'Notas de desplazamiento / Deployment notes',
        ];
    }

    /**
     * @param  Payroll  $payroll
     * @return list<string|float|null>
     */
    public function map($payroll): array
    {
        // Legacy rows can hold undecryptable payloads — export them as 0.
        $payroll->healUndecryptable();

        $money = fn (string $field): float => (float) ($payroll->getAttribute($field) ?? 0);

        return [
            $payroll->month,
            $payroll->employee?->full_name,
            $payroll->company?->name,
            (float) $payroll->attendance_days,
            (float) $payroll->attendance_hours,
            (float) $payroll->overtime_hours,
            $payroll->wage_type?->value,
            $money('base_salary'),
            $money('days_amount'),
            $money('hours_amount'),
            $money('overtime_pay'),
            $money('reimbursements'),
            $money('project_expenses'),
            $money('gross_pay'),
            $money('advance_deductions'),
            $money('other_deductions'),
            $money('manual_additions'),
            $money('net_amount'),
            $payroll->status->value,
            $payroll->payment_method?->value,
            $payroll->paid_at?->toDateString(),
            // Option A: "Deployed to X — cost transferred"
            implode(' | ', $payroll->deployment_notes ?? []),
        ];
    }
}
