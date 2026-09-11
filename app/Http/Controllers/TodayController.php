<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Exports\TodayBreakdownExport;
use App\Exports\TodayExport;
use App\Exports\TodayProjectsExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Services\Audit\AuditLogger;
use App\Services\Dashboard\TodayService;
use App\Support\CompanyBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screen 15 — Today's Report. Per selected company, auto-refreshed by the
 * client every 5 minutes.
 */
class TodayController extends Controller
{
    use ResolvesCompanyContext;

    public function __construct(private readonly TodayService $today) {}

    /**
     * @return array{search: string, project: int|null, statuses: list<string>, from: string|null, to: string|null}
     */
    private function resolveFilters(Request $request): array
    {
        $valid = array_map(fn (AttendanceStatus $s): string => $s->value, AttendanceStatus::cases());
        $requested = is_array($request->query('statuses')) ? $request->query('statuses') : [];
        $statuses = array_values(array_intersect(array_map('strval', $requested), $valid));

        return [
            'search' => trim((string) $request->query('search', '')),
            'project' => is_numeric($request->query('project')) ? (int) $request->query('project') : null,
            'statuses' => $statuses,
            'from' => $this->validDate($request->query('from')),
            'to' => $this->validDate($request->query('to')),
        ];
    }

    /** A valid Y-m-d date string, or null. */
    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function index(Request $request): Response
    {
        $companyId = $this->contextCompanyId();

        $data = $this->today->for($companyId, $this->resolveFilters($request));

        // Advance amounts are encrypted pay data — strip them for anyone
        // without the right to see pay (same rule as the payroll screen).
        if (! Gate::allows('payroll.view')) {
            $data['pending']['advances_pending'] = array_map(
                function (array $row): array {
                    unset($row['amount']);

                    return $row;
                },
                $data['pending']['advances_pending'],
            );
        }

        return Inertia::render('Today/Index', [
            'data' => $data,
            'can' => [
                'view_pay' => Gate::allows('payroll.view'),
            ],
        ]);
    }

    /**
     * Export the filtered worker-detail view (Excel or PDF).
     */
    public function export(Request $request, AuditLogger $audit): BinaryFileResponse|HttpResponse
    {
        $companyId = $this->contextCompanyId();
        $data = $this->today->for($companyId, $this->resolveFilters($request));
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['attendance'];
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'excel';

        $audit->log('exported', null, null, null, 'Today report '.strtoupper($format), 'other');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.today-pdf', [
                'rows' => $rows,
                'generated_at' => (string) $data['generated_at'],
                'logo' => CompanyBranding::currentLogo(),
            ])->download('informe-hoy.pdf');
        }

        return Excel::download(new TodayExport($rows), 'informe-hoy.xlsx');
    }

    /**
     * Export ONLY the "Project Breakdown" section (Excel or PDF) — a clean,
     * readable document for sharing (Change 2), with the employees column +
     * totals row.
     */
    public function exportBreakdown(Request $request, AuditLogger $audit): BinaryFileResponse|HttpResponse
    {
        $companyId = $this->contextCompanyId();
        $data = $this->today->for($companyId, $this->resolveFilters($request));
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['project_breakdown'];
        /** @var array<string, int|float> $totals */
        $totals = $data['project_breakdown_totals'];
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'excel';

        $audit->log('exported', null, null, null, 'Today project breakdown '.strtoupper($format), 'other');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.today-breakdown-pdf', [
                'rows' => $rows,
                'totals' => $totals,
                'generated_at' => (string) $data['generated_at'],
                'logo' => CompanyBranding::currentLogo(),
            ])->download('desglose-obras.pdf');
        }

        return Excel::download(new TodayBreakdownExport($rows, $totals), 'desglose-obras.xlsx');
    }

    /**
     * Export ONLY the "Projects with no activity today" section (Excel or PDF).
     */
    public function exportProjects(Request $request, AuditLogger $audit): BinaryFileResponse|HttpResponse
    {
        $companyId = $this->contextCompanyId();
        $data = $this->today->for($companyId, $this->resolveFilters($request));
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['projects_no_activity'];
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'excel';

        $audit->log('exported', null, null, null, 'Today no-activity projects '.strtoupper($format), 'other');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.today-projects-pdf', [
                'rows' => $rows,
                'generated_at' => (string) $data['generated_at'],
                'logo' => CompanyBranding::currentLogo(),
            ])->download('proyectos-sin-actividad.pdf');
        }

        return Excel::download(new TodayProjectsExport($rows), 'proyectos-sin-actividad.xlsx');
    }
}
