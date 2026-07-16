<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
use App\Http\Requests\Invoices\UpdateInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\Audit\AuditLogger;
use App\Services\Invoices\InvoiceService;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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

    public function index(Request $request): Response
    {
        Gate::authorize('invoices.view');

        $tab = $request->string('tab')->value() === InvoiceType::Expense->value
            ? InvoiceType::Expense
            : InvoiceType::Sale;

        $invoices = Invoice::query()
            ->where('type', $tab->value)
            ->with(['client:id,name', 'vendor:id,name', 'project:id,name', 'company:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term)));
            })
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->string('to')))
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $i): array => $this->row($i));

        return Inertia::render('Invoices/Index', [
            'tab' => $tab->value,
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'payment_status', 'project_id', 'from', 'to']),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'vatOptions' => VatRate::options(),
            'paymentMethods' => array_map(fn ($m) => $m->value, PaymentMethod::cases()),
            'paymentStatuses' => array_map(fn ($s) => $s->value, PaymentStatus::cases()),
            'statuses' => array_map(fn ($s) => $s->value, InvoiceStatus::cases()),
            'can' => [
                // Creating needs a company to issue the invoice; a Super Admin
                // browsing all companies has none, so don't offer a dead end.
                'create' => Gate::allows('invoices.create') && app(CurrentCompany::class)->id() !== null,
                'edit' => Gate::allows('invoices.edit'),
                'delete' => Gate::allows('invoices.delete'),
                'export' => Gate::allows('invoices.export'),
            ],
        ]);
    }

    /**
     * The detail slide-panel payload (line items + payments), loaded on demand.
     */
    public function show(Invoice $invoice): Response
    {
        Gate::authorize('invoices.view');

        $invoice->load(['lineItems', 'payments', 'client:id,name', 'vendor:id,name', 'project:id,name']);

        return Inertia::render('Invoices/Index', [
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
        ]);
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

        $invoice->delete();

        return back()->with('success', __('ui.invoices.deleted'));
    }

    public function pdf(Invoice $invoice, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('invoices.export');

        $invoice->load(['lineItems', 'client', 'vendor', 'project', 'company']);
        $audit->log('exported', $invoice, null, null, 'Invoice PDF', 'invoices');

        $pdf = Pdf::loadView('exports.invoice-pdf', ['invoice' => $invoice]);

        return $pdf->download('factura-'.$invoice->number.'.pdf');
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
            'vat_percent' => $i->vat_rate?->percent(),
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
