<?php

namespace App\Http\Controllers;

use App\Enums\BearableBy;
use App\Enums\ExpenseType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Exports\ExpensesExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\CompanyCard;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSplit;
use App\Models\Project;
use App\Models\SubcontractorPayment;
use App\Models\Vendor;
use App\Rules\OwnCompanyEmployee;
use App\Rules\OwnCompanyProject;
use App\Services\Audit\AuditLogger;
use App\Services\Vehicles\VehicleExpenseSyncService;
use App\Services\Workers\WorkerFuelExpenseService;
use App\Support\CompanyBranding;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Screen 10 Gastos / Screen 09 Tab 6 — supplier and worker costs.
 *
 * An expense carrying BOTH employee_id and project_id is a "worker project
 * expense" and is pulled into that employee's payroll for the month
 * (PayrollService) — so the two fields together are load-bearing, not just tags.
 *
 * `approved`/`approved_by`/`approved_at` and `file_path` are set directly, never
 * mass-assigned (same rule as Measurement + Document).
 */
class ExpenseController extends Controller
{
    use ResolvesCompanyContext;

    /**
     * The list query, shared by the screen and the Excel/PDF exports so that
     * "export the filtered view" is literal and the two can never drift.
     *
     * @return Builder<Expense>
     */
    private function filteredQuery(Request $request): Builder
    {
        return Expense::query()
            ->with(['vendor:id,name', 'project:id,name', 'employee:id,full_name', 'category:id,name', 'splits.category:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', $request->integer('expense_category_id')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            // ->string() returns a Stringable — compare on ->value(), or the
            // filter silently never matches.
            ->when($request->filled('approval'), fn ($q) => $q->where('approved', $request->string('approval')->value() === 'approved'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->string('to')))
            ->orderByDesc('date')->orderByDesc('id');
    }

    /**
     * Summary-card counts + € totals (company-scoped), in ONE query.
     *
     * @return array{
     *   total: array{count: int, amount: float},
     *   approved: array{count: int, amount: float},
     *   pending: array{count: int, amount: float},
     * }
     */
    private function expenseStats(): array
    {
        $r = Expense::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN approved = 1 THEN 1 ELSE 0 END), 0) as approved_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN approved = 1 THEN total ELSE 0 END), 0) as approved_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN approved = 0 THEN 1 ELSE 0 END), 0) as pending_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN approved = 0 THEN total ELSE 0 END), 0) as pending_sum')
            ->first();

        return [
            'total' => ['count' => (int) ($r->total_count ?? 0), 'amount' => (float) ($r->total_sum ?? 0)],
            'approved' => ['count' => (int) ($r->approved_count ?? 0), 'amount' => (float) ($r->approved_sum ?? 0)],
            'pending' => ['count' => (int) ($r->pending_count ?? 0), 'amount' => (float) ($r->pending_sum ?? 0)],
        ];
    }

    public function index(Request $request): Response
    {
        Gate::authorize('expenses.view');

        $expenses = $this->filteredQuery($request)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Expense $e): array => $this->row($e));

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'stats' => $this->expenseStats(),
            'filters' => (object) $request->only(['search', 'project_id', 'vendor_id', 'type', 'expense_category_id', 'payment_status', 'approval', 'from', 'to']),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            // Filter dropdown = all projects; create form uses active-only `formProjects`.
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'formProjects' => Project::query()->active()->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->active()->orderBy('full_name')->get(['id', 'full_name']),
            // All own-company + group-wide categories (active and inactive) so the
            // management modal can show them; the form/filter show only active ones.
            'categories' => ExpenseCategory::query()
                ->forCompany(app(CurrentCompany::class)->id())
                ->orderBy('name')->get(['id', 'name', 'active', 'company_id']),
            'cards' => CompanyCard::query()->where('active', true)->orderBy('label')->get(['id', 'label', 'last_four']),
            'types' => array_map(fn (ExpenseType $t): string => $t->value, ExpenseType::userSelectable()),
            'vatOptions' => VatRate::options(),
            'paymentMethods' => array_map(fn ($m) => $m->value, PaymentMethod::cases()),
            'paymentStatuses' => array_map(fn ($s) => $s->value, PaymentStatus::cases()),
            'bearableByOptions' => BearableBy::values(),
            'can' => [
                // Permission-only: shown to anyone who may create. The Vue gate
                // routes a company-less Super Admin to the picker; the store's
                // contextCompanyId() is the server-side safety net.
                'create' => Gate::allows('expenses.create'),
                'edit' => Gate::allows('expenses.edit'),
                'delete' => Gate::allows('expenses.delete'),
                'approve' => Gate::allows('expenses.approve'),
                'export' => Gate::allows('expenses.export'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('expenses.create');

        $companyId = $this->contextCompanyId();
        $expense = new Expense($this->validated($request));
        $expense->company_id = $companyId;
        $this->applyTotals($expense);
        $this->applyBearer($expense);
        $this->storeAttachment($request, $expense);

        // Multi-category split (Smart Expense Split): amounts sum to the total.
        $splits = $this->validatedSplits($request, (float) $expense->total, $companyId);
        if ($splits !== null) {
            $expense->expense_category_id = null; // the breakdown lives in the split rows
        }

        DB::transaction(function () use ($expense, $splits): void {
            $expense->save();
            $this->syncSplits($expense, $splits);
        });

        return back()->with('success', __('ui.expenses.saved'));
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.edit');

        abort_if($expense->approved, 422, 'Approved expenses cannot be edited.');

        $expense->fill($this->validated($request));
        $this->applyTotals($expense);
        $this->applyBearer($expense);
        $this->storeAttachment($request, $expense);

        $splits = $this->validatedSplits($request, (float) $expense->total, (int) $expense->company_id);
        if ($splits !== null) {
            $expense->expense_category_id = null;
        }

        DB::transaction(function () use ($expense, $splits): void {
            $expense->save();
            $this->syncSplits($expense, $splits);
        });

        return back()->with('success', __('ui.expenses.saved'));
    }

    /**
     * Validate the optional category split. Returns null for a single-category
     * expense; otherwise a normalised list whose amounts sum EXACTLY to the
     * expense total. Splits need >=2 rows and own-company (or group-default)
     * categories.
     *
     * @return list<array{expense_category_id: int, amount: float, description: string|null}>|null
     */
    private function validatedSplits(Request $request, float $total, int $companyId): ?array
    {
        if (! is_array($request->input('splits')) || $request->input('splits') === []) {
            return null;
        }

        $validated = $request->validate([
            'splits' => ['array', 'min:2'],
            'splits.*.expense_category_id' => ['required', 'integer',
                Rule::exists('expense_categories', 'id')->where(
                    fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId),
                )],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'splits.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $sum = round(array_sum(array_map(fn (array $s): float => (float) $s['amount'], $validated['splits'])), 2);
        if (abs($sum - round($total, 2)) > 0.001) {
            throw ValidationException::withMessages([
                'splits' => __('ui.expenses.split_sum_mismatch', [
                    'sum' => number_format($sum, 2), 'total' => number_format($total, 2),
                ]),
            ]);
        }

        return array_map(fn (array $s): array => [
            'expense_category_id' => (int) $s['expense_category_id'],
            'amount' => (float) $s['amount'],
            'description' => isset($s['description']) && $s['description'] !== '' ? (string) $s['description'] : null,
        ], array_values($validated['splits']));
    }

    /**
     * Replace the expense's split rows. amount + expense_id are set directly
     * (not mass assignable).
     *
     * @param  list<array{expense_category_id: int, amount: float, description: string|null}>|null  $splits
     */
    private function syncSplits(Expense $expense, ?array $splits): void
    {
        $expense->splits()->delete();

        if ($splits === null) {
            return;
        }

        foreach ($splits as $i => $s) {
            $split = new ExpenseSplit([
                'expense_category_id' => $s['expense_category_id'],
                'description' => $s['description'],
                'sort_order' => $i,
            ]);
            $split->expense_id = $expense->id;
            $split->amount = (string) $s['amount'];
            $split->save();
        }
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        // Admin-level FINAL approval (separation of duties): this is the gate that
        // releases the money into payroll. Distinct from the manager-level
        // expenses.approve on the Worker Expenses screen.
        Gate::authorize('expenses.approve_final');

        $validated = $request->validate(['approved' => ['required', 'boolean']]);

        // A subcontractor payment's auto-posted Gasto must never be APPROVED:
        // the P&L already counts the payment itself, and the approved-expense
        // sum would count the same money twice (spec C9). Its non-approval is
        // load-bearing, not an oversight.
        if ($validated['approved'] && SubcontractorPayment::query()
            ->withoutGlobalScopes()->where('expense_id', $expense->id)->exists()) {
            throw ValidationException::withMessages([
                'approved' => __('ui.expenses.subcontractor_expense_locked'),
            ]);
        }

        // Not mass-assignable — set directly (Measurement/Document convention).
        // A worker-sourced mirror expense (source worker_fuel / worker_expense)
        // is NOW approvable here — this final approval is exactly what makes
        // payroll count the reimbursement (the old auto_fuel_locked guard is
        // gone; payroll no longer counts the WorkerExpense at the manager step).
        $expense->approved = $validated['approved'];
        $expense->approved_by = $validated['approved'] ? Auth::id() : null;
        $expense->approved_at = $validated['approved'] ? now() : null;
        $expense->save();

        // Part E — on final approval, mirror a vehicle-linked expense into the
        // matching vehicle_* table so it shows on the vehicle's Fuel/Fine/
        // Maintenance tab (idempotent).
        if ($expense->approved) {
            app(VehicleExpenseSyncService::class)->syncOnFinalApproval($expense);
        }

        return back()->with('success', __('ui.expenses.'.($validated['approved'] ? 'approved' : 'rejected')));
    }

    /**
     * The CURRENT FILTERED VIEW as a spreadsheet — same query as the screen.
     */
    public function export(Request $request, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('expenses.export');

        $rows = $this->filteredQuery($request)->get();
        $audit->log('exported', new Expense, null, null, 'Expenses Excel', 'expenses');

        return Excel::download(new ExpensesExport($rows), 'gastos.xlsx');
    }

    /**
     * The CURRENT FILTERED VIEW as a PDF — same query as the screen.
     */
    public function exportPdf(Request $request, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('expenses.export');

        $rows = $this->filteredQuery($request)->get();
        $audit->log('exported', new Expense, null, null, 'Expenses PDF', 'expenses');

        $pdf = Pdf::loadView('exports.expenses-pdf', ['expenses' => $rows, 'logo' => CompanyBranding::currentLogo()])->setPaper('a4', 'landscape');

        return $pdf->download('gastos.pdf');
    }

    /**
     * Serve the private receipt file — gated, tenancy-scoped (route-model binding
     * 404s a cross-company id) and audited, mirroring the worker-expense and
     * document download routes (Rule 10).
     */
    public function downloadReceipt(Expense $expense, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('expenses.view');

        abort_if($expense->file_path === null, 404);

        $audit->log('viewed', $expense, null, null, 'Expense receipt', 'expenses');

        return Storage::disk('local')->download($expense->file_path, $expense->original_name ?? basename($expense->file_path));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.delete');

        abort_if($expense->approved, 422, 'Approved expenses cannot be removed.');

        // A worker-sourced mirror expense REFERENCES the worker expense's receipt
        // file (shared, not copied) — deleting it here would destroy the
        // worker's own receipt, so leave the file and only drop the mirror row.
        $isWorkerMirror = in_array($expense->source, [WorkerFuelExpenseService::SOURCE, WorkerFuelExpenseService::SOURCE_GENERAL], true);
        if ($expense->file_path !== null && ! $isWorkerMirror) {
            Storage::disk('local')->delete($expense->file_path);
        }

        $expense->delete();

        return back()->with('success', __('ui.expenses.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'number' => ['nullable', 'string', 'max:40'],
            // Only the human-facing types: internal_deployment is posted by the
            // cross-charge engine and must never arrive from a form.
            'type' => ['required', Rule::in(array_map(fn (ExpenseType $t): string => $t->value, ExpenseType::userSelectable()))],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            // Own-company only: an employee+project pair from another company
            // would be paid back through THAT company's payroll.
            'project_id' => ['nullable', 'integer', new OwnCompanyProject],
            'employee_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'company_card_id' => ['nullable', 'integer', Rule::exists('company_cards', 'id')],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'subtotal' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'vat_rate' => ['nullable', Rule::enum(VatRate::class)],
            // A custom rate needs its percentage; other rates ignore it.
            'vat_custom_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:vat_rate,custom'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'payment_date' => ['nullable', 'date'],
            'bearable_by' => ['required', Rule::enum(BearableBy::class)],
            'deduct_from_salary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * Derive the reimbursement flag from the bearer (the legacy model, replacing
     * the old manual checkbox): the worker is paid back only when THEY bear a
     * cost they fronted — not when it is flagged for salary deduction (that is a
     * company-card cost they must repay). Salary deduction only applies to an
     * employee-borne cost.
     */
    private function applyBearer(Expense $expense): void
    {
        $bearer = $expense->bearable_by;

        if ($bearer !== BearableBy::Employee) {
            $expense->deduct_from_salary = false;
        }

        $expense->is_reimbursable = $bearer === BearableBy::Employee && ! $expense->deduct_from_salary;
    }

    /**
     * VAT + total are derived, never trusted from input — a blank rate means no
     * VAT at all, not 0% (DECISIONS.md).
     */
    private function applyTotals(Expense $expense): void
    {
        // Only a custom rate keeps a custom percentage.
        if ($expense->vat_rate !== VatRate::Custom) {
            $expense->vat_custom_percent = null;
        }

        $subtotal = (float) $expense->subtotal;
        $vat = $expense->vat_rate instanceof VatRate
            ? $expense->vat_rate->amountFor($subtotal, $expense->vat_custom_percent)
            : 0.0;

        $expense->vat_amount = (string) $vat;
        $expense->total = (string) round($subtotal + $vat, 2);
    }

    private function storeAttachment(Request $request, Expense $expense): void
    {
        // Same whitelist discipline as documents and leave attachments — a
        // receipt is a PDF or a photo, never an executable.
        $request->validate(['file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']]);

        if (! $request->hasFile('file')) {
            return;
        }

        // Private disk, randomized name, original kept as metadata — the same
        // handling as the documents engine.
        $path = $request->file('file')->store('expenses/'.($expense->company_id ?? 0), 'local');
        $expense->file_path = $path === false ? null : $path;
        $expense->original_name = $request->file('file')->getClientOriginalName();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Expense $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'type' => $e->type->value,
            'vendor' => $e->vendor?->name,
            'vendor_id' => $e->vendor_id,
            'project' => $e->project?->name,
            'project_id' => $e->project_id,
            'employee' => $e->employee?->full_name,
            'employee_id' => $e->employee_id,
            'category' => $e->category?->name,
            'expense_category_id' => $e->expense_category_id,
            // Multi-category split (Smart Expense Split). is_split → the list
            // shows "Multiple categories"; splits[] prefill the edit form.
            'is_split' => $e->splits->isNotEmpty(),
            'splits' => $e->splits->map(fn (ExpenseSplit $s): array => [
                'expense_category_id' => $s->expense_category_id,
                'category' => $s->category?->name,
                'amount' => (float) $s->amount,
                'description' => $s->description,
            ])->all(),
            'company_card_id' => $e->company_card_id,
            'date' => $e->date->toDateString(),
            'due_date' => $e->due_date?->toDateString(),
            'subtotal' => (float) $e->subtotal,
            'vat_rate' => $e->vat_rate?->value,
            'vat_custom_percent' => $e->vat_custom_percent,
            'vat_amount' => (float) $e->vat_amount,
            'total' => (float) $e->total,
            'payment_method' => $e->payment_method?->value,
            'payment_status' => $e->payment_status->value,
            'payment_date' => $e->payment_date?->toDateString(),
            'approved' => $e->approved,
            'is_reimbursable' => $e->is_reimbursable,
            'bearable_by' => $e->bearable_by->value,
            'deduct_from_salary' => $e->deduct_from_salary,
            'notes' => $e->notes,
            'has_file' => $e->file_path !== null,
            'original_name' => $e->original_name,
            // Non-null when the row was auto-created (e.g. 'worker_fuel').
            'source' => $e->source,
        ];
    }
}
