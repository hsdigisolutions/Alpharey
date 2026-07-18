<?php

namespace App\Services\Search;

use App\Models\Client;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Vehicle;
use App\Models\Vendor;
use Illuminate\Support\Facades\Gate;

/**
 * Global search across the nine entity types (header bar, Phase 8).
 *
 * Two guarantees, both server-side:
 *  - PERMISSION: a group is searched only if the user may view that module.
 *    Hiding a result in the dropdown is never the control — the query simply
 *    is not run.
 *  - TENANCY: company-owned models (employees, projects, invoices, expenses,
 *    vehicles, documents) run through their global scope, so a search can only
 *    ever reach the active company's rows. Clients, vendors and proposals are
 *    the shared pool by design (Phase 3) and are searched across the group.
 *
 * Encrypted fields (NIF, IBAN, salary) are never search targets here — they
 * have blind indexes for that on their own screens; a name/code/number match
 * is all the global bar promises.
 */
class GlobalSearch
{
    private const PER_GROUP = 5;

    /**
     * @return array<int, array{module: string, results: list<array<string, mixed>>}>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.$term.'%';
        $groups = [];

        foreach ($this->providers($like) as $module => $provider) {
            $results = $provider();

            if ($results !== []) {
                $groups[] = ['module' => $module, 'results' => $results];
            }
        }

        return $groups;
    }

    /**
     * Each provider is gated and only invoked if the user may view it.
     *
     * @return array<string, callable(): list<array<string, mixed>>>
     */
    private function providers(string $like): array
    {
        $out = [];

        if (Gate::allows('employees.view')) {
            $out['employees'] = fn (): array => Employee::query()
                ->where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('employee_code', 'like', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Employee $e): array => ['id' => $e->id, 'label' => $e->full_name, 'sub' => $e->employee_code, 'href' => "/employees/{$e->id}"])
                ->all();
        }

        if (Gate::allows('projects.view')) {
            $out['projects'] = fn (): array => Project::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Project $p): array => ['id' => $p->id, 'label' => $p->name, 'sub' => $p->code, 'href' => "/projects/{$p->id}"])
                ->all();
        }

        if (Gate::allows('clients.view')) {
            $out['clients'] = fn (): array => Client::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('company_name', 'like', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Client $c): array => ['id' => $c->id, 'label' => $c->name, 'sub' => $c->company_name, 'href' => "/clients/{$c->id}"])
                ->all();
        }

        if (Gate::allows('vendors.view')) {
            $out['vendors'] = fn (): array => Vendor::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('company_name', 'like', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Vendor $v): array => ['id' => $v->id, 'label' => $v->name, 'sub' => $v->company_name, 'href' => "/vendors/{$v->id}"])
                ->all();
        }

        if (Gate::allows('invoices.view')) {
            $out['invoices'] = fn (): array => Invoice::query()
                ->where('number', 'like', $like)
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Invoice $i): array => ['id' => $i->id, 'label' => $i->number, 'sub' => $i->type->value, 'href' => '/invoices'])
                ->all();
        }

        if (Gate::allows('expenses.view')) {
            $out['expenses'] = fn (): array => Expense::query()
                ->where('number', 'like', $like)
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Expense $e): array => ['id' => $e->id, 'label' => $e->number ?? "#{$e->id}", 'sub' => null, 'href' => '/expenses'])
                ->all();
        }

        if (Gate::allows('proposals.view')) {
            $out['proposals'] = fn (): array => Proposal::query()
                ->where('number', 'like', $like)
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Proposal $p): array => ['id' => $p->id, 'label' => $p->number, 'sub' => null, 'href' => '/proposals'])
                ->all();
        }

        if (Gate::allows('vehicles.view')) {
            $out['vehicles'] = fn (): array => Vehicle::query()
                ->where(fn ($q) => $q->where('plate_number', 'like', $like)->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Vehicle $v): array => ['id' => $v->id, 'label' => $v->plate_number, 'sub' => trim("{$v->brand} {$v->model}"), 'href' => "/vehicles/{$v->id}"])
                ->all();
        }

        if (Gate::allows('documents.view')) {
            $out['documents'] = fn (): array => Document::query()
                ->where('is_current', true)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('type_key', 'like', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (Document $d): array => ['id' => $d->id, 'label' => $d->name ?? $d->type_key, 'sub' => $d->category, 'href' => '/compliance'])
                ->all();
        }

        return $out;
    }
}
