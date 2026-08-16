<?php

namespace App\Http\Controllers;

use App\Enums\CommissionStatus;
use App\Exports\CommissionsExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\CommissionReportEntry;
use App\Models\Employee;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Commissions\CommissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screen 19 — Commission Reports. Company-owned.
 *
 * Commission is pay-adjacent, so the module has its own permission
 * (commission_reports.*) and finalizing is a one-way door.
 */
class CommissionController extends Controller
{
    use ResolvesCompanyContext;

    public function index(Request $request): Response
    {
        Gate::authorize('commission_reports.view');

        $month = $this->resolveMonth($request);

        $entries = CommissionReportEntry::query()
            ->where('month', $month)
            ->with(['employee:id,full_name', 'project:id,name', 'invoice:id,number,total,paid_amount'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('employee_id')
            ->get()
            ->map(fn (CommissionReportEntry $e): array => $this->row($e))
            ->values();

        return Inertia::render('Commissions/Index', [
            'month' => $month,
            'entries' => $entries,
            'filters' => (object) $request->only(['employee_id', 'project_id', 'status']),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => array_map(fn ($s) => $s->value, CommissionStatus::cases()),
            'total' => round((float) $entries->sum('amount'), 2),
            'can' => [
                'edit' => Gate::allows('commission_reports.edit'),
                'approve' => Gate::allows('commission_reports.approve'),
                'export' => Gate::allows('commission_reports.export'),
            ],
        ]);
    }

    public function generate(Request $request, CommissionService $service): RedirectResponse
    {
        Gate::authorize('commission_reports.edit');

        $count = $service->generateMonth($this->contextCompanyId(), $this->resolveMonth($request));

        return back()->with('success', __('ui.commissions.generated', ['count' => $count]));
    }

    public function adjust(Request $request, CommissionReportEntry $entry, CommissionService $service): RedirectResponse
    {
        Gate::authorize('commission_reports.edit');

        $validated = $request->validate([
            'adjusted_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            // An adjustment without a reason is unauditable — require it.
            'adjustment_reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->adjust($entry, (float) $validated['adjusted_amount'], $validated['adjustment_reason']);

        return back()->with('success', __('ui.commissions.adjusted'));
    }

    public function finalize(CommissionReportEntry $entry, CommissionService $service): RedirectResponse
    {
        Gate::authorize('commission_reports.approve');

        $service->finalize($entry);

        return back()->with('success', __('ui.commissions.finalized'));
    }

    public function markPaid(CommissionReportEntry $entry, CommissionService $service): RedirectResponse
    {
        Gate::authorize('commission_reports.approve');

        $service->markPaid($entry);

        return back()->with('success', __('ui.commissions.marked_paid'));
    }

    public function export(Request $request, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('commission_reports.export');

        $month = $this->resolveMonth($request);

        $entries = CommissionReportEntry::query()
            ->where('month', $month)
            ->with(['employee:id,full_name', 'project:id,name', 'invoice:id,number,total,paid_amount'])
            ->orderBy('employee_id')
            ->get();

        $audit->log('exported', new CommissionReportEntry, null, null, 'Commission Excel '.$month, 'commission_reports');

        return Excel::download(new CommissionsExport($entries), 'comisiones-'.$month.'.xlsx');
    }

    public function pdf(Request $request, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('commission_reports.export');

        $month = $this->resolveMonth($request);

        $entries = CommissionReportEntry::query()
            ->where('month', $month)
            ->with(['employee:id,full_name', 'project:id,name', 'invoice:id,number'])
            ->orderBy('employee_id')
            ->get();

        $audit->log('exported', new CommissionReportEntry, null, null, 'Commission PDF '.$month, 'commission_reports');

        $pdf = Pdf::loadView('exports.commissions-pdf', ['entries' => $entries, 'month' => $month]);

        return $pdf->download('comisiones-'.$month.'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CommissionReportEntry $e): array
    {
        return [
            'id' => $e->id,
            'month' => $e->month,
            'employee' => $e->employee?->full_name,
            'project' => $e->project?->name,
            'invoice' => $e->invoice?->number,
            'invoice_total' => $e->invoice !== null ? (float) $e->invoice->total : null,
            'invoice_paid' => $e->invoice !== null ? (float) $e->invoice->paid_amount : null,
            'commission_percent' => (float) $e->commission_percent,
            'base_amount' => (float) $e->base_amount,
            'original_amount' => (float) $e->original_amount,
            'adjusted_amount' => $e->adjusted_amount !== null ? (float) $e->adjusted_amount : null,
            'adjustment_reason' => $e->adjustment_reason,
            // what is actually owed: the adjustment when present, else original
            'amount' => $e->payableAmount(),
            'status' => $e->status->value,
            'finalized_at' => $e->finalized_at?->toDateTimeString(),
            'paid_at' => $e->paid_at?->toDateString(),
            'notes' => $e->notes,
        ];
    }

    private function resolveMonth(Request $request): string
    {
        $raw = $request->string('month')->value();

        return $raw !== '' && preg_match('/^\d{4}-\d{2}$/', $raw)
            ? $raw
            : Carbon::now()->format('Y-m');
    }
}
