<?php

namespace App\Http\Controllers;

use App\Enums\BearableBy;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\MeasurementStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Exports\InvoicesExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
use App\Http\Requests\Invoices\UpdateInvoiceRequest;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Measurement;
use App\Models\Project;
use App\Models\Vendor;
use App\Rules\OwnCompanyProject;
use App\Services\Audit\AuditLogger;
use App\Services\Invoices\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screen 10 — Invoices. Two tabs on one screen: Ventas (money in, to a client)
 * and Gastos (money out, from a vendor). Company-owned.
 *
 * Totals and payment_status are never taken from input — InvoiceTotals derives
 * them (REQUIREMENTS.md §10). VAT is the VatRate dropdown with a blank default;
 * the spec's "default 21%" is overridden by DECISIONS.md.
 */
class InvoiceController extends Controller
{
    use ResolvesCompanyContext;

    /**
     * The list query, shared by the screen and the Excel export so that
     * "export the filtered view" is literal (REQUIREMENTS.md §10) and the two
     * can never drift apart.
     *
     * @return Builder<Invoice>
     */
    private function filteredQuery(Request $request, InvoiceType $tab): Builder
    {
        return Invoice::query()
            ->where('type', $tab->value)
            ->with(['client:id,name', 'vendor:id,name', 'project:id,name', 'company:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term)));
            })
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            // Summary-card filter: invoice status (draft / sent / paid).
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->string('to')))
            ->orderByDesc('invoice_date')->orderByDesc('id');
    }

    /**
     * Summary-card counts + € totals for the current tab, in ONE query.
     *
     * @return array{
     *   total: array{count: int, amount: float},
     *   draft: array{count: int, amount: float},
     *   sent: array{count: int, amount: float},
     *   paid: array{count: int, amount: float},
     * }
     */
    private function invoiceStats(InvoiceType $tab): array
    {
        $r = Invoice::query()->where('type', $tab->value)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_sum')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) as draft_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'draft' THEN total ELSE 0 END), 0) as draft_sum")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) as sent_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'sent' THEN total ELSE 0 END), 0) as sent_sum")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) as paid_sum")
            ->first();

        return [
            'total' => ['count' => (int) ($r->total_count ?? 0), 'amount' => (float) ($r->total_sum ?? 0)],
            'draft' => ['count' => (int) ($r->draft_count ?? 0), 'amount' => (float) ($r->draft_sum ?? 0)],
            'sent' => ['count' => (int) ($r->sent_count ?? 0), 'amount' => (float) ($r->sent_sum ?? 0)],
            'paid' => ['count' => (int) ($r->paid_count ?? 0), 'amount' => (float) ($r->paid_sum ?? 0)],
        ];
    }

    private function resolveTab(Request $request): InvoiceType
    {
        return $request->string('tab')->value() === InvoiceType::Expense->value
            ? InvoiceType::Expense
            : InvoiceType::Sale;
    }

    /**
     * The shared page props for the invoices screen — used by both index() and
     * show(). Keeping show() a COMPLETE page (not just `editing`) is what stops a
     * full navigation to /invoices/{id} — e.g. the redirect after recording a
     * payment — from rendering a blank page with missing required props.
     *
     * @return array<string, mixed>
     */
    private function pageProps(Request $request, InvoiceType $tab): array
    {
        $invoices = $this->filteredQuery($request, $tab)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $i): array => $this->row($i));

        return [
            'tab' => $tab->value,
            'invoices' => $invoices,
            'stats' => $this->invoiceStats($tab),
            'filters' => (object) $request->only(['search', 'payment_status', 'status', 'project_id', 'from', 'to']),
            // `clients` = all (so editing an invoice keeps a since-inactive client);
            // the create form selects from active-only `formClients` (Change 4).
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'formClients' => Client::query()->active()->orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            // Filter dropdown = all projects (browse any); the create form uses the
            // active-only `formProjects` below (Change 4 — selection = active only).
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'client_id']),
            'formProjects' => Project::query()->active()->orderBy('name')->get(['id', 'name', 'client_id']),
            'vatOptions' => VatRate::options(),
            'paymentMethods' => array_map(fn ($m) => $m->value, PaymentMethod::cases()),
            'paymentStatuses' => array_map(fn ($s) => $s->value, PaymentStatus::cases()),
            'statuses' => array_map(fn ($s) => $s->value, InvoiceStatus::cases()),
            'can' => [
                // Permission-only: the button is shown to anyone who may create.
                // A Super Admin browsing all companies has no company context —
                // the Vue gate routes them to pick one, and the store's
                // ResolvesCompanyContext is the server-side safety net.
                'create' => Gate::allows('invoices.create'),
                'edit' => Gate::allows('invoices.edit'),
                'delete' => Gate::allows('invoices.delete'),
                'export' => Gate::allows('invoices.export'),
            ],
        ];
    }

    public function index(Request $request): Response
    {
        Gate::authorize('invoices.view');

        $props = $this->pageProps($request, $this->resolveTab($request));

        // Opened from a project's Invoices tab: preset (and lock) the project +
        // its client so the new sale invoice is scoped to that project.
        if ($request->integer('preset_project') > 0) {
            $project = Project::query()->find($request->integer('preset_project'));
            if ($project !== null) {
                $props['preset'] = ['project_id' => $project->id, 'client_id' => $project->client_id];
            }
        }

        return Inertia::render('Invoices/Index', $props);
    }

    /**
     * A complete page with the slide-panel pre-opened (line items + payments).
     * Reached both by the partial `only: ['editing']` reload from the list and by
     * a full navigation (a payment redirect, a bookmarked URL) — hence the full
     * props, so a full load is never blank.
     */
    public function show(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('invoices.view');

        $invoice->load(['lineItems', 'payments', 'client:id,name', 'vendor:id,name', 'project:id,name']);

        return Inertia::render('Invoices/Index', array_merge(
            $this->pageProps($request, $invoice->type),
            [
                'editing' => array_merge($this->row($invoice), [
                    'lines' => $invoice->lineItems->map(fn ($l): array => [
                        'description' => $l->description,
                        'quantity' => (float) $l->quantity,
                        'unit_price' => (float) $l->unit_price,
                        'line_total' => (float) $l->line_total,
                    ])->all(),
                    'payments' => $invoice->payments->map(fn ($p): array => [
                        'id' => $p->id,
                        'amount' => (float) $p->amount,
                        'payment_date' => $p->payment_date->toDateString(),
                        'payment_method' => $p->payment_method?->value,
                        'reference' => $p->reference,
                    ])->all(),
                    'notes' => $invoice->notes,
                ]),
            ],
        ));
    }

    /**
     * An invoice is always issued BY one company, so creating needs a company
     * context. A Super Admin browsing "all companies" has none — send them to
     * Welcome to pick one rather than fail on a null company_id.
     */
    public function store(StoreInvoiceRequest $request, InvoiceService $service): RedirectResponse
    {
        $service->create($request->validated(), $this->contextCompanyId());

        return back()->with('success', __('ui.invoices.saved'));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->update($invoice, $request->validated());

        return back()->with('success', __('ui.invoices.saved'));
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        Gate::authorize('invoices.delete');

        // payments cascade on delete: removing an invoice with money received
        // would erase the payment records too. Delete the payments first —
        // each one audited and re-deriving the status — then the invoice.
        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => __('ui.invoices.delete_has_payments'),
            ]);
        }

        $invoice->delete();

        return back()->with('success', __('ui.invoices.deleted'));
    }

    /**
     * The CURRENT FILTERED VIEW as a spreadsheet — same query as the screen.
     */
    public function export(Request $request, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('invoices.export');

        $tab = $this->resolveTab($request);
        $rows = $this->filteredQuery($request, $tab)->get();

        $audit->log('exported', new Invoice, null, null, 'Invoices Excel ('.$tab->value.')', 'invoices');

        return Excel::download(new InvoicesExport($rows), 'facturas-'.$tab->value.'.xlsx');
    }

    /**
     * Auto-calculate invoice line items from a project (the legacy "Method 2"),
     * offering the admin three bases:
     *  - costs   → labour + client-borne approved expenses, itemised, × margin
     *  - subtotal→ one line: total project cost × margin
     *  - meter   → approved measured metres × the project's client meter rate
     * Only CLIENT-bearable expenses count (the Bearable-By rule). Returns JSON;
     * the totals are still (re)derived server-side by InvoiceTotals on save.
     */
    public function projectCosts(Request $request): JsonResponse
    {
        Gate::authorize('invoices.create');

        $data = $request->validate([
            'project_id' => ['required', 'integer', new OwnCompanyProject],
            'method' => ['required', Rule::in(['costs', 'subtotal', 'meter'])],
            'margin' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $projectId = (int) $data['project_id'];
        $project = Project::findOrFail($projectId);
        $margin = 1 + ((float) ($data['margin'] ?? 0)) / 100;
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        $inRange = function (Builder $q) use ($from, $to): Builder {
            return $q->when($from, fn ($w) => $w->whereDate('date', '>=', $from))
                ->when($to, fn ($w) => $w->whereDate('date', '<=', $to));
        };

        $labour = (float) $inRange(Attendance::query()->where('project_id', $projectId))->sum('total_amount');

        /** @var Collection<int, Expense> $clientExpenses */
        $clientExpenses = $inRange(Expense::query()
            ->where('project_id', $projectId)
            ->where('approved', true)
            ->where('bearable_by', BearableBy::Client->value))
            ->get(['expense_category_id', 'total']);

        $lines = [];

        if ($data['method'] === 'meter') {
            $metres = (float) $inRange(Measurement::query()
                ->where('project_id', $projectId)
                ->where('status', MeasurementStatus::Approved->value))->sum('quantity');
            $rate = (float) ($project->getAttribute('client_meter_rate') ?? 0);
            $lines[] = [
                'description' => __('ui.invoices.calc_meter_line'),
                'quantity' => round($metres, 2),
                'unit_price' => round($rate * $margin, 2),
            ];
        } elseif ($data['method'] === 'subtotal') {
            $cost = ($labour + (float) $clientExpenses->sum(fn (Expense $e): float => (float) $e->total)) * $margin;
            $lines[] = ['description' => $project->name, 'quantity' => 1, 'unit_price' => round($cost, 2)];
        } else { // costs — itemised: labour + one line per expense category
            if ($labour > 0) {
                $lines[] = ['description' => __('ui.invoices.calc_labour_line'), 'quantity' => 1, 'unit_price' => round($labour * $margin, 2)];
            }

            foreach ($clientExpenses->groupBy('expense_category_id') as $rows) {
                $amount = (float) $rows->sum(fn (Expense $e): float => (float) $e->total);
                $categoryId = $rows->first()?->expense_category_id;
                $name = $categoryId !== null ? ExpenseCategory::find($categoryId)?->name : null;
                $lines[] = [
                    'description' => $name ?? __('ui.invoices.calc_expenses_line'),
                    'quantity' => 1,
                    'unit_price' => round($amount * $margin, 2),
                ];
            }
        }

        if ($lines === []) {
            $lines[] = ['description' => $project->name, 'quantity' => 1, 'unit_price' => 0];
        }

        return response()->json(['lines' => $lines]);
    }

    public function pdf(Invoice $invoice, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('invoices.export');

        $invoice->load(['lineItems', 'client', 'vendor', 'project', 'company']);
        $audit->log('exported', $invoice, null, null, 'Invoice PDF', 'invoices');

        $pdf = Pdf::loadView('exports.invoice-pdf', [
            'invoice' => $invoice,
            'logo' => $this->companyLogoData($invoice->company),
        ]);

        return $pdf->download('factura-'.$invoice->number.'.pdf');
    }

    /**
     * The issuing company's logo as a base64 data URI for the PDF — DomPDF cannot
     * fetch remote assets and the CSP forbids external images, so the bytes must
     * be embedded. Null when no logo is uploaded or the file is missing (the PDF
     * then simply shows the company name).
     */
    private function companyLogoData(?Company $company): ?string
    {
        $path = $company?->logo_path;

        if ($path === null || $path === '') {
            return null;
        }

        foreach (['public', 'local'] as $disk) {
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }

            $contents = Storage::disk($disk)->get($path);

            if ($contents === null) {
                continue;
            }

            $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'type' => $i->type->value,
            'sub_type' => $i->sub_type->value,
            'client' => $i->client?->name,
            'client_id' => $i->client_id,
            'vendor' => $i->vendor?->name,
            'vendor_id' => $i->vendor_id,
            'project' => $i->project?->name,
            'project_id' => $i->project_id,
            'company' => $i->company?->name,
            'invoice_date' => $i->invoice_date->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'billing_type' => $i->billing_type,
            'billing_period' => $i->billing_period,
            'subtotal' => (float) $i->subtotal,
            // null vat_rate renders as "—", never as 0% (design-skill VAT rule)
            'vat_rate' => $i->vat_rate?->value,
            'vat_custom_percent' => $i->vat_custom_percent,
            'vat_percent' => $i->vat_rate?->effectivePercent($i->vat_custom_percent),
            'vat_amount' => (float) $i->vat_amount,
            'discount_type' => $i->discount_type?->value,
            'discount_value' => (float) $i->discount_value,
            'discount_amount' => (float) $i->discount_amount,
            'retention_percent' => $i->retention_percent !== null ? (float) $i->retention_percent : null,
            'retention_amount' => (float) $i->retention_amount,
            'total' => (float) $i->total,
            'paid_amount' => (float) $i->paid_amount,
            'status' => $i->status->value,
            'payment_status' => $i->payment_status->value,
            'payment_date' => $i->payment_date?->toDateString(),
            'payment_method' => $i->payment_method?->value,
        ];
    }
}
