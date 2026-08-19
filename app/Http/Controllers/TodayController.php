<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Exports\TodayExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Services\Audit\AuditLogger;
use App\Services\Dashboard\TodayService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
     * @return array{search: string, project: int|null, status: string|null}
     */
    private function resolveFilters(Request $request): array
    {
        $statuses = array_map(fn (AttendanceStatus $s): string => $s->value, AttendanceStatus::cases());

        return [
            'search' => trim((string) $request->query('search', '')),
            'project' => is_numeric($request->query('project')) ? (int) $request->query('project') : null,
            'status' => in_array($request->query('status'), $statuses, true) ? (string) $request->query('status') : null,
        ];
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
            ])->download('informe-hoy.pdf');
        }

        return Excel::download(new TodayExport($rows), 'informe-hoy.xlsx');
    }
}
