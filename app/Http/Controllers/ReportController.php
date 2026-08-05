<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Services\Reports\ReportService;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screen 14 — Reports. One page, a persistent filter bar (module + date
 * range), every report module, and PDF/Excel export of the filtered view.
 *
 * Per selected company (SA without a selection → Welcome, decision 27). Each
 * module additionally requires the viewing right for the data it exposes, so a
 * user with reports.view but not payroll.view cannot pull the payroll report.
 */
class ReportController extends Controller
{
    use ResolvesCompanyContext;

    /**
     * The extra module-view gate a report needs beyond reports.view. Modules
     * not listed here only need reports.view.
     *
     * @var array<string, string>
     */
    private const MODULE_GATE = [
        'payroll' => 'payroll.view',
        'commission' => 'commission_reports.view',
        'financial' => 'invoices.view',
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        $this->contextCompanyId();
        Gate::authorize('reports.view');

        $module = $this->resolveModule($request);

        return Inertia::render('Reports/Index', [
            'module' => $module,
            'filters' => $this->filters($request),
            'modules' => $this->availableModules(),
            'report' => $this->canSee($module) ? $this->reports->for($module, $this->filters($request)) : null,
            'blocked' => ! $this->canSee($module),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $this->contextCompanyId();
        Gate::authorize('reports.export');

        $module = $this->resolveModule($request);
        abort_unless($this->canSee($module), 403);

        $report = $this->reports->for($module, $this->filters($request));

        return Excel::download(
            new ReportExport($module, $report),
            "report-{$module}-".now()->format('Ymd').'.xlsx',
        );
    }

    public function exportPdf(Request $request): \Illuminate\Http\Response
    {
        $this->contextCompanyId();
        Gate::authorize('reports.export');

        $module = $this->resolveModule($request);
        abort_unless($this->canSee($module), 403);

        $rawFilters = $this->filters($request);
        $report = $this->reports->for($module, $rawFilters);

        // Resolve the default range so the PDF header always shows the actual
        // period queried (not "— → —" when the user hasn't set a date).
        $pdfFilters = [
            'from' => $rawFilters['from'] ?? now()->startOfYear()->toDateString(),
            'to' => $rawFilters['to'] ?? now()->toDateString(),
        ];

        return Pdf::loadView('exports.report-pdf', [
            'module' => $module,
            'filters' => $pdfFilters,
            'report' => $report,
            'company' => app(CurrentCompany::class)->get()?->name,
        ])->download("report-{$module}-".now()->format('Ymd').'.pdf');
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->filled('from') ? $request->string('from')->value() : null,
            'to' => $request->filled('to') ? $request->string('to')->value() : null,
        ];
    }

    private function resolveModule(Request $request): string
    {
        $module = $request->string('module')->value();

        return in_array($module, ReportService::MODULES, true) ? $module : 'employees';
    }

    private function canSee(string $module): bool
    {
        $gate = self::MODULE_GATE[$module] ?? null;

        return $gate === null || Gate::allows($gate);
    }

    /**
     * The modules this user may actually open (for the dropdown).
     *
     * @return list<string>
     */
    private function availableModules(): array
    {
        return array_values(array_filter(ReportService::MODULES, fn (string $m): bool => $this->canSee($m)));
    }
}
