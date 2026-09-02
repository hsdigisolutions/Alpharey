<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceExport;
use App\Models\Attendance;
use App\Services\Attendance\AttendanceService;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class AttendanceImportExportController extends Controller
{
    public function export(Request $request, AuditLogger $audit): Response
    {
        Gate::authorize('attendance.export');

        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->value())
            ? Carbon::createFromFormat('Y-m', $request->string('month')->value())->startOfMonth()
            : now()->startOfMonth();

        $records = Attendance::query()
            ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->with(['employee:id,full_name', 'project:id,name'])
            ->orderBy('date')
            ->get();

        $withWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        // NET worked hours in the sheet (a full 08:00–17:00 day reads 8 h).
        $breakMinutes = app(AttendanceService::class)->breakDurationMinutes(app(CurrentCompany::class)->id() ?? 0);

        $audit->log('exported', null, null, null, 'Attendance export ('.$records->count().' rows)', 'attendance');

        return Excel::download(
            new AttendanceExport($records, $withWages, $breakMinutes),
            'asistencia-'.$month->format('Y-m').'.xlsx',
        );
    }

    public function template(): Response
    {
        Gate::authorize('attendance.create');

        return Excel::download(new class implements FromArray
        {
            /** @return list<list<string>> */
            public function array(): array
            {
                return [
                    ['employee_code', 'fecha', 'entrada', 'salida', 'estado'],
                    ['E1-0001', '2026-07-01', '08:00', '17:00', 'present'],
                ];
            }
        }, 'plantilla-asistencia.xlsx');
    }
}
