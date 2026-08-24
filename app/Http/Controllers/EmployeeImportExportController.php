<?php

namespace App\Http\Controllers;

use App\Exports\EmployeesExport;
use App\Imports\EmployeesImport;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeQueryFilter;
use App\Services\Employees\EmployeeService;
use App\Support\CompanyBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class EmployeeImportExportController extends Controller
{
    /**
     * Excel/PDF export of the current filtered view. Audited as 'exported'.
     */
    public function export(Request $request, EmployeeQueryFilter $filter, AuditLogger $audit): Response
    {
        Gate::authorize('employees.export');

        $employees = $filter->apply($request)->with('company')->orderBy('full_name')->get();
        $withWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        $audit->log('exported', null, null, null, 'Employees export ('.$employees->count().' rows)', 'employees');

        if ($request->string('format')->value() === 'pdf') {
            $pdf = Pdf::loadView('exports.employees-pdf', [
                'employees' => $employees,
                'withWages' => $withWages,
                'logo' => CompanyBranding::currentLogo(),
            ])->setPaper('a4', 'landscape');

            return $pdf->download('empleados-'.now()->format('Ymd-His').'.pdf');
        }

        return Excel::download(
            new EmployeesExport($employees, $withWages),
            'empleados-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    /**
     * Downloadable import template with bilingual headings + example row.
     */
    public function template(): Response
    {
        Gate::authorize('employees.create');

        return Excel::download(new class implements FromArray
        {
            /** @return list<list<string>> */
            public function array(): array
            {
                return [
                    ['nombre', 'nif', 'email', 'movil', 'ciudad', 'departamento', 'puesto', 'fecha_alta', 'tipo_salario', 'tarifa', 'salario_base'],
                    ['María García López', '12345678Z', 'maria@ejemplo.es', '600123456', 'Madrid', 'Obra', 'Oficial 1ª', '2024-03-01', 'hourly', '14.50', ''],
                ];
            }
        }, 'plantilla-empleados.xlsx');
    }

    public function import(Request $request, EmployeeService $service): RedirectResponse
    {
        Gate::authorize('employees.create');

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new EmployeesImport($service);

        Excel::import($import, $request->file('file'));

        if ($import->failures !== []) {
            $summary = collect($import->failures)
                ->map(fn (array $failure) => "Fila/Row {$failure['row']}: {$failure['errors']}")
                ->take(10)
                ->implode(' · ');

            return back()->with(
                $import->imported > 0 ? 'success' : 'error',
                __('ui.employees.import_partial', ['imported' => $import->imported]).' '.$summary,
            );
        }

        return back()->with('success', __('ui.employees.import_done', ['imported' => $import->imported]));
    }
}
