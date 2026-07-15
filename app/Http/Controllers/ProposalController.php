<?php

namespace App\Http\Controllers;

use App\Enums\ProposalStatus;
use App\Enums\VatRate;
use App\Http\Requests\Proposals\StoreProposalRequest;
use App\Http\Requests\Proposals\UpdateProposalRequest;
use App\Models\Client;
use App\Models\Proposal;
use App\Services\Audit\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Screen 18 — Proposals. Shared across companies. VAT optional via the
 * VatRate dropdown; totals computed server-side from line items so the
 * stored figures are authoritative.
 */
class ProposalController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('proposals.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $proposals = Proposal::query()
            ->with(['client:id,name', 'project:id,name'])
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->value();
                $q->where(fn (Builder $q) => $q
                    ->where('number', 'like', "%{$term}%")
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Proposal $p): array => [
                'id' => $p->id,
                'number' => $p->number,
                'client' => $p->client?->name,
                'project' => $p->project?->name,
                'proposal_date' => $p->proposal_date?->toDateString(),
                'expiry_date' => $p->expiry_date?->toDateString(),
                'subtotal' => $p->subtotal,
                'vat_rate' => $p->vat_rate?->value,
                'vat_amount' => $p->vat_amount,
                'total_amount' => $p->total_amount,
                'status' => $p->status->value,
            ]);

        return Inertia::render('Proposals/Index', [
            'proposals' => $proposals,
            'filters' => $request->only(['search', 'status', 'per_page']),
            'statuses' => array_map(fn (ProposalStatus $s) => $s->value, ProposalStatus::cases()),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'vatOptions' => VatRate::options(),
            'can' => [
                'create' => Gate::allows('proposals.create'),
                'edit' => Gate::allows('proposals.edit'),
                'delete' => Gate::allows('proposals.delete'),
                'export' => Gate::allows('proposals.export'),
            ],
        ]);
    }

    public function store(StoreProposalRequest $request): RedirectResponse
    {
        $data = $this->withTotals($request->validated());
        $data['number'] = Proposal::nextNumber();

        Proposal::query()->create($data);

        return back()->with('success', __('ui.proposals.saved'));
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal): RedirectResponse
    {
        $proposal->update($this->withTotals($request->validated()));

        return back()->with('success', __('ui.proposals.saved'));
    }

    public function destroy(Proposal $proposal): RedirectResponse
    {
        Gate::authorize('proposals.delete');

        $proposal->delete();

        return back()->with('success', __('ui.proposals.deleted'));
    }

    public function pdf(Proposal $proposal, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('proposals.export');

        $proposal->load('client', 'project');
        $audit->log('exported', $proposal, null, null, 'Proposal PDF', 'proposals');

        $pdf = Pdf::loadView('exports.proposal-pdf', ['proposal' => $proposal]);

        return $pdf->download('propuesta-'.$proposal->number.'.pdf');
    }

    /**
     * Compute subtotal / VAT / total from line items so stored figures are
     * authoritative (never trust client-side totals). VAT optional.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withTotals(array $data): array
    {
        $subtotal = 0.0;

        foreach ($data['line_items'] ?? [] as $item) {
            $subtotal += (float) ($item['qty'] ?? 0) * (float) ($item['unit_price'] ?? 0);
        }

        $data['subtotal'] = round($subtotal, 2);

        $rate = isset($data['vat_rate']) ? VatRate::tryFrom((string) $data['vat_rate']) : null;
        $data['vat_amount'] = $rate?->amountFor($data['subtotal']);
        $data['total_amount'] = round($data['subtotal'] + (float) ($data['vat_amount'] ?? 0), 2);

        return $data;
    }
}
