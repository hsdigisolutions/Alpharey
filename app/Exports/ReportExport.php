<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Screen 14 — generic report export. Each report module has a different
 * primary table; this maps the module to its headings + rows so "export the
 * filtered view" produces the same table the screen shows. The figures block
 * rides along as the first rows so the export is self-contained.
 */
class ReportExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(private string $module, private array $report) {}

    public function title(): string
    {
        return ucfirst($this->module);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return match ($this->module) {
            'attendance' => ['Empleado / Employee', 'Días / Days', 'Horas / Hours'],
            'payroll' => ['Empleado / Employee', 'Neto / Net'],
            'projects' => ['Proyecto / Project', 'Horas / Hours'],
            'profitability' => ['Proyecto / Project', 'Cliente / Client', 'Horas / Hours', 'Ingresos / Revenue', 'Coste MO / Labour', 'Gastos / Expenses', 'Beneficio / Profit', 'Margen % / Margin %'],
            'commission' => ['Empleado / Employee', 'Proyecto / Project', '%', 'Original', 'Ajustado / Adjusted', 'Estado / Status'],
            'timesheet' => ['Empleado / Employee', 'Proyecto / Project', 'Horas / Hours'],
            'deployments' => ['Empleado / Employee', 'De / From', 'A / To', 'Proyecto / Project', 'Inicio / Start', 'Fin / End', 'Facturación / Billing', 'Estado / Status'],
            'financial' => ['Factura / Invoice', 'Total', 'Fecha / Date'],
            'documents' => ['Tipo / Type', 'Total'],
            default => ['Clave / Key', 'Total'],
        };
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function array(): array
    {
        return match ($this->module) {
            'attendance' => array_map(fn (array $r): array => [$r['employee'], $r['days'], $r['hours']], $this->report['by_employee']),
            'payroll' => array_map(fn (array $r): array => [$r['employee'], $r['net']], $this->report['by_employee']),
            'projects' => array_map(fn (array $r): array => [$r['project'], $r['hours']], $this->report['hours_per_project']),
            'profitability' => array_map(fn (array $r): array => [
                $r['project'], $r['client'], $r['hours'], $r['revenue'], $r['coste_mo'], $r['gastos'], $r['profit'], $r['margin'] ?? '—',
            ], $this->report['rows']),
            'commission' => array_map(fn (array $r): array => [$r['employee'], $r['project'], $r['percent'], $r['original'], $r['adjusted'], $r['status']], $this->report['rows']),
            'timesheet' => array_map(fn (array $r): array => [$r['employee'], $r['project'], $r['hours']], $this->report['rows']),
            'deployments' => array_map(fn (array $r): array => [$r['employee'], $r['from'], $r['to'], $r['project'], $r['start'], $r['end'], $r['billing_method'], $r['status']], $this->report['rows']),
            'financial' => array_map(fn (array $r): array => [$r['number'], $r['total'], $r['date']], $this->report['unpaid_invoices']),
            'documents' => $this->keyTotalRows($this->report['by_type']),
            default => $this->keyTotalRows($this->report['by_designation']),
        };
    }

    /**
     * @param  array<string, int|float>  $map
     * @return list<array<int, mixed>>
     */
    private function keyTotalRows(array $map): array
    {
        $rows = [];

        foreach ($map as $key => $total) {
            $rows[] = [$key === '' ? '—' : $key, $total];
        }

        return $rows;
    }
}
