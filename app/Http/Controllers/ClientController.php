<?php

namespace App\Http\Controllers;

use App\Enums\ClientType;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\DocumentPanelPayload;
use App\Support\DocumentTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 07 — Clients. Shared pool: NO company scope — every company sees
 * the same clients (dev skill Rule 1). Still permission-gated + audited.
 */
class ClientController extends Controller
{
    private const SORTABLE = ['name', 'company_name', 'city', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('clients.view');

        $sort = in_array($request->string('sort')->value(), self::SORTABLE, true)
            ? $request->string('sort')->value()
            : 'name';
        $dir = $request->string('dir')->value() === 'desc' ? 'desc' : 'asc';
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $clients = Client::query()
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->value();
                $q->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%")
                    ->orWhere('nif', 'like', "%{$term}%"));
            })
            ->when($request->filled('client_type'), fn (Builder $q) => $q->where('client_type', $request->string('client_type')))
            ->when($request->string('status')->value() === 'active', fn (Builder $q) => $q->where('active', true))
            ->when($request->string('status')->value() === 'inactive', fn (Builder $q) => $q->where('active', false))
            ->withCount('projects')
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'company_name' => $client->company_name,
                'nif' => $client->nif,
                'client_type' => $client->client_type->value,
                'contact_person' => $client->contact_person,
                'phone' => $client->phone,
                'email' => $client->email,
                'city' => $client->city,
                'payment_terms' => $client->payment_terms,
                'active' => $client->active,
                'projects_count' => $client->projects_count,
            ]);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => (object) $request->only(['search', 'client_type', 'status', 'sort', 'dir', 'per_page']),
            'clientTypes' => array_map(fn (ClientType $t) => $t->value, ClientType::cases()),
            'can' => [
                'create' => Gate::allows('clients.create'),
                'edit' => Gate::allows('clients.edit'),
                'delete' => Gate::allows('clients.delete'),
                'export' => Gate::allows('clients.export'),
            ],
        ]);
    }

    public function show(Client $client, DocumentPanelPayload $panel): Response
    {
        Gate::authorize('clients.view');

        return Inertia::render('Clients/Detail', [
            'client' => $client->only([
                'id', 'name', 'company_name', 'nif', 'vat_number', 'client_type',
                'contact_person', 'phone', 'mobile', 'email', 'address', 'city',
                'postal_code', 'country', 'website', 'bank_account', 'payment_terms',
                'industry', 'company_size', 'preferred_contact', 'active', 'notes',
            ]),
            'contacts' => $client->contacts()->get(['id', 'name', 'designation', 'email', 'phone', 'alternate_phone']),
            'projects' => $client->projects()->with('company:id,name')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'code' => $p->code,
                    'company' => $p->company?->name,
                    'status' => $p->status->value,
                    'start_date' => $p->start_date?->toDateString(),
                    'budget' => $p->budget,
                ]),
            'proposals' => $client->proposals()->get(['id', 'number', 'status', 'proposal_date', 'total_amount']),
            'communications' => $client->communications()->with('author:id,name')->orderByDesc('logged_at')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'type' => $c->type,
                    'body' => $c->body,
                    'logged_at' => $c->logged_at->toDateTimeString(),
                    'author' => $c->author?->name,
                ]),
            // Facturas tab — the client is a shared record, but invoices are
            // company-owned, so the tenant scope shows only THIS company's
            // invoices to the client, never another company's.
            'invoices' => Gate::allows('invoices.view') ? $this->clientInvoices($client) : [],
            'canViewInvoices' => Gate::allows('invoices.view'),
            // Documentos tab — the shared client's docs, scoped to this company.
            'documents' => Gate::allows('documents.view') ? $panel->forEntity($client, 'client') : [],
            'documentSets' => DocumentTypes::client(),
            'documentFieldDefs' => DocumentTypes::fieldDefsMap('client'),
            'can' => [
                'edit' => Gate::allows('clients.edit'),
                'delete' => Gate::allows('clients.delete'),
                'view' => Gate::allows('documents.view'),
                'upload' => Gate::allows('documents.upload'),
                'download' => Gate::allows('documents.download'),
                'deleteDocs' => Gate::allows('documents.delete'),
                'editDocs' => Gate::allows('documents.edit'),
            ],
        ]);
    }

    /**
     * This company's invoices to the client (tenant-scoped), newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function clientInvoices(Client $client): array
    {
        return Invoice::query()
            ->where('client_id', $client->id)
            ->orderByDesc('invoice_date')
            ->get()
            ->map(fn (Invoice $i): array => [
                'id' => $i->id,
                'number' => $i->number,
                'date' => $i->invoice_date->toDateString(),
                'total' => (float) $i->total,
                'status' => $i->payment_status->value,
            ])
            ->all();
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        Client::query()->create($request->validated());

        return back()->with('success', __('ui.clients.saved'));
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        return back()->with('success', __('ui.clients.saved'));
    }

    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('clients.delete');

        $client->delete(); // soft delete — clients are never hard-deleted

        return redirect()->route('clients.index')->with('success', __('ui.clients.deleted'));
    }
}
