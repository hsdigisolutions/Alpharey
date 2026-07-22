<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\CompanyCard;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Vendor;
use App\Rules\OwnCompanyEmployee;
use App\Rules\OwnCompanyProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

    public function index(Request $request): Response
    {
        Gate::authorize('expenses.view');

        $expenses = Expense::query()
            ->with(['vendor:id,name', 'project:id,name', 'employee:id,full_name', 'category:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            // ->string() returns a Stringable — compare on ->value(), or the
            // filter silently never matches.
            ->when($request->filled('approval'), fn ($q) => $q->where('approved', $request->string('approval')->value() === 'approved'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->string('to')))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Expense $e): array => $this->row($e));

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $request->only(['search', 'project_id', 'vendor_id', 'approval', 'from', 'to']),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('active', true)->orderBy('full_name')->get(['id', 'full_name']),
            'categories' => ExpenseCategory::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'cards' => CompanyCard::query()->where('active', true)->orderBy('label')->get(['id', 'label', 'last_four']),
            'types' => array_map(fn (ExpenseType $t): string => $t->value, ExpenseType::userSelectable()),
            'vatOptions' => VatRate::options(),
            'paymentMethods' => array_map(fn ($m) => $m->value, PaymentMethod::cases()),
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

        $expense = new Expense($this->validated($request));
        $expense->company_id = $this->contextCompanyId();
        $this->applyTotals($expense);
        $this->storeAttachment($request, $expense);
        $expense->save();

        return back()->with('success', __('ui.expenses.saved'));
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.edit');

        abort_if($expense->approved, 422, 'Approved expenses cannot be edited.');

        $expense->fill($this->validated($request));
        $this->applyTotals($expense);
        $this->storeAttachment($request, $expense);
        $expense->save();

        return back()->with('success', __('ui.expenses.saved'));
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.approve');

        $validated = $request->validate(['approved' => ['required', 'boolean']]);

        // Not mass-assignable — set directly (Measurement/Document convention).
        $expense->approved = $validated['approved'];
        $expense->approved_by = $validated['approved'] ? Auth::id() : null;
        $expense->approved_at = $validated['approved'] ? now() : null;
        $expense->save();

        return back()->with('success', __('ui.expenses.'.($validated['approved'] ? 'approved' : 'rejected')));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.delete');

        abort_if($expense->approved, 422, 'Approved expenses cannot be removed.');

        if ($expense->file_path !== null) {
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
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'payment_date' => ['nullable', 'date'],
            'is_reimbursable' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * VAT + total are derived, never trusted from input — a blank rate means no
     * VAT at all, not 0% (DECISIONS.md).
     */
    private function applyTotals(Expense $expense): void
    {
        $subtotal = (float) $expense->subtotal;
        $vat = $expense->vat_rate instanceof VatRate ? $expense->vat_rate->amountFor($subtotal) : 0.0;

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
            'project' => $e->project?->name,
            'employee' => $e->employee?->full_name,
            'category' => $e->category?->name,
            'date' => $e->date->toDateString(),
            'due_date' => $e->due_date?->toDateString(),
            'subtotal' => (float) $e->subtotal,
            'vat_rate' => $e->vat_rate?->value,
            'vat_amount' => (float) $e->vat_amount,
            'total' => (float) $e->total,
            'payment_method' => $e->payment_method?->value,
            'payment_status' => $e->payment_status->value,
            'approved' => $e->approved,
            'is_reimbursable' => $e->is_reimbursable,
            'has_file' => $e->file_path !== null,
        ];
    }
}
