<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Exports\PayrollExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\LockedPeriod;
use App\Models\Payroll;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\PeriodLock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screen 12 — Payroll. Company-owned.
 *
 * Pay figures are the most sensitive data in the system: every amount in the
 * props is gated on `payroll.view` and nulled out otherwise. The Payroll model
 * additionally hides them from serialization, so this controller has to opt
 * each figure in deliberately.
 */
class PayrollController extends Controller
{
    /**
     * A payroll run is always "this company's month" — there is no meaningful
     * all-companies view to calculate, approve or lock. So a Super Admin
     * browsing without a selection is sent to Welcome to pick one first,
     * exactly like the other company-scoped admin screens.
     */
    use ResolvesCompanyContext;

    public function index(Request $request): Response
    {
        Gate::authorize('payroll.view');

        $companyId = $this->contextCompanyId();
        $month = $this->resolveMonth($request);

        $rows = Payroll::query()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->with(['employee:id,full_name,designation', 'company:id,name'])
            ->get()
            ->map(fn (Payroll $p): array => $this->row($p))
            ->sortBy('employee')
            ->values();

        return Inertia::render('Payroll/Index', [
            'month' => $month,
            'rows' => $rows,
            'summary' => $this->summary($rows),
            'locked' => LockedPeriod::query()
                ->where('company_id', $companyId)->where('month', $month)->exists(),
            'paymentMethods' => array_map(fn ($m) => $m->value, PaymentMethod::cases()),
            'can' => [
                'create' => Gate::allows('payroll.create'),
                'edit' => Gate::allows('payroll.edit'),
                'approve' => Gate::allows('payroll.approve'),
                'export' => Gate::allows('payroll.export'),
                'download' => Gate::allows('payroll.download'),
            ],
        ]);
    }

    public function calculate(Request $request, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll.create');

        $companyId = $this->contextCompanyId();

        $month = $this->resolveMonth($request);
        $count = $service->calculateMonth($companyId, $month);

        // The month's payroll is ready to review — notify the admins/managers.
        if ($count > 0) {
            app(NotificationDispatcher::class)->dispatch(NotificationType::PayrollReady, $companyId, [
                'title_es' => "Nómina lista para revisar ({$month})",
                'title_en' => "Payroll ready to review ({$month})",
                'entity' => $month, 'url' => '/payroll',
            ]);
        }

        return back()->with('success', __('ui.payroll.calculated', ['count' => $count]));
    }

    public function approveAll(Request $request, PayrollWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('payroll.approve');

        $companyId = $this->contextCompanyId();
        $month = $this->resolveMonth($request);

        $workflow->approveAll($companyId, $month);

        // Approval is a Super-Admin-visible event (sign-off before payment).
        app(NotificationDispatcher::class)->dispatch(NotificationType::PayrollApproved, $companyId, [
            'title_es' => "Nómina aprobada ({$month})",
            'title_en' => "Payroll approved ({$month})",
            'entity' => $month, 'url' => '/payroll',
        ]);

        return back()->with('success', __('ui.payroll.approved'));
    }

    public function markPaid(Request $request, Payroll $payroll, PayrollWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('payroll.edit');

        app(PeriodLock::class)->assertOpen($payroll->company_id, $payroll->month);

        $validated = $request->validate([
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $method = isset($validated['payment_method'])
            ? PaymentMethod::from($validated['payment_method'])
            : null;

        $workflow->markPaid($payroll, $method);

        return back()->with('success', __('ui.payroll.marked_paid'));
    }

    /**
     * Manual adjustments — the clerk's own numbers. Recalculating the month
     * preserves them (PayrollService::calculateFor).
     */
    public function adjust(Request $request, Payroll $payroll, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll.edit');

        app(PeriodLock::class)->assertOpen($payroll->company_id, $payroll->month);

        abort_if($payroll->status === PayrollStatus::Paid, 422, 'Paid payroll cannot be adjusted.');

        $validated = $request->validate([
            'other_deductions' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'manual_additions' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payroll->other_deductions = (string) ($validated['other_deductions'] ?? 0);
        $payroll->manual_additions = (string) ($validated['manual_additions'] ?? 0);
        $payroll->notes = $validated['notes'] ?? null;
        $payroll->save();

        // Re-derive net from the stored components with the new adjustments.
        $employee = $payroll->employee;
        if ($employee !== null) {
            $service->calculateFor($employee, $payroll->company_id, $payroll->month, $payroll);
        }

        return back()->with('success', __('ui.payroll.adjusted'));
    }

    public function lockPeriod(Request $request, PayrollWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('payroll.approve');

        $companyId = $this->contextCompanyId();

        $workflow->lockPeriod($companyId, $this->resolveMonth($request));

        return back()->with('success', __('ui.payroll.locked'));
    }

    public function unlockPeriod(Request $request, PayrollWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('payroll.approve');

        $companyId = $this->contextCompanyId();

        $workflow->unlockPeriod($companyId, $this->resolveMonth($request));

        return back()->with('success', __('ui.payroll.unlocked'));
    }

    /**
     * The month as a spreadsheet. Gated on payroll.view as well as .export —
     * the sheet is nothing but pay figures, so there is no "without wages"
     * variant the way the employees export has one.
     */
    public function export(Request $request, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('payroll.export');
        Gate::authorize('payroll.view');

        $companyId = $this->contextCompanyId();
        $month = $this->resolveMonth($request);

        $rows = Payroll::query()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->with(['employee:id,full_name', 'company:id,name'])
            ->get()
            ->sortBy(fn (Payroll $p) => $p->employee?->full_name)
            ->values();

        $audit->log('exported', new Payroll, null, null, 'Payroll Excel '.$month, 'payroll');

        return Excel::download(new PayrollExport($rows), 'nominas-'.$month.'.xlsx');
    }

    /**
     * Every payslip for the month in one PDF (spec: "Export PDF (all payslips)").
     */
    public function payslips(Request $request, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('payroll.download');
        Gate::authorize('payroll.view');

        $companyId = $this->contextCompanyId();
        $month = $this->resolveMonth($request);

        $rows = Payroll::query()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->with(['employee:id,full_name,designation', 'company:id,name'])
            ->get()
            ->sortBy(fn (Payroll $p) => $p->employee?->full_name)
            ->values();

        $audit->log('exported', new Payroll, null, null, 'Payslips PDF '.$month, 'payroll');

        $pdf = Pdf::loadView('exports.payslips-bulk-pdf', ['payrolls' => $rows, 'month' => $month]);

        return $pdf->download('nominas-'.$month.'.pdf');
    }

    /**
     * Internal management payslip (DECISIONS.md) — never an official nómina.
     * Gated on payroll.download AND payroll.view: the PDF is nothing but pay
     * figures, so seeing it requires the right to see pay.
     */
    public function payslip(Payroll $payroll, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('payroll.download');
        Gate::authorize('payroll.view');

        $payroll->load('employee', 'company');
        $audit->log('exported', $payroll, null, null, 'Payslip PDF', 'payroll');

        $pdf = Pdf::loadView('exports.payslip-pdf', ['payroll' => $payroll]);

        return $pdf->download('nomina-'.$payroll->month.'-'.$payroll->employee_id.'.pdf');
    }

    /**
     * The list/breakdown shape. Every money figure is opt-in on payroll.view.
     *
     * @return array<string, mixed>
     */
    private function row(Payroll $p): array
    {
        $money = fn (string $field): ?float => Gate::allows('payroll.view')
            ? (float) ($p->getAttribute($field) ?? 0)
            : null;

        return [
            'id' => $p->id,
            'employee_id' => $p->employee_id,
            'employee' => $p->employee?->full_name,
            'designation' => $p->employee?->designation,
            'company' => $p->company?->name,
            'month' => $p->month,
            'attendance_days' => (float) $p->attendance_days,
            'attendance_hours' => (float) $p->attendance_hours,
            'overtime_hours' => (float) $p->overtime_hours,
            'wage_type' => $p->wage_type?->value,
            'wage_rate' => $money('wage_rate'),
            'base_salary' => $money('base_salary'),
            'days_amount' => $money('days_amount'),
            'hours_amount' => $money('hours_amount'),
            // Per-day-type breakdown (jornadas completas/medias/horas/metros).
            // The *Safe readers tolerate an undecryptable legacy payload — a
            // bad row must degrade to null, never 500 the whole screen.
            'day_type_summary' => Gate::allows('payroll.view') ? $p->dayTypeSummarySafe() : null,
            // Mid-month rate changes (null unless ≥2 periods) — spec C10: the
            // breakdown modal shows the same per-period lines as the payslip.
            'rate_periods' => Gate::allows('payroll.view') ? $p->ratePeriodsSafe() : null,
            'overtime_pay' => $money('overtime_pay'),
            'reimbursements' => $money('reimbursements'),
            'project_expenses' => $money('project_expenses'),
            'gross_pay' => $money('gross_pay'),
            'advance_deductions' => $money('advance_deductions'),
            'fine_deductions' => $money('fine_deductions'),
            'expense_deductions' => $money('expense_deductions'),
            'other_deductions' => $money('other_deductions'),
            'manual_additions' => $money('manual_additions'),
            'net_amount' => $money('net_amount'),
            'status' => $p->status->value,
            'payment_method' => $p->payment_method?->value,
            'paid_at' => $p->paid_at?->toDateString(),
            'deployment_notes' => $p->deployment_notes,
            'notes' => $p->notes,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        return [
            'employees' => $rows->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'paid' => $rows->where('status', 'paid')->count(),
            'net_total' => Gate::allows('payroll.view')
                ? round((float) $rows->sum(fn (array $r) => (float) ($r['net_amount'] ?? 0)), 2)
                : null,
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
