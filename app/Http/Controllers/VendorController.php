<?php

namespace App\Http\Controllers;

use App\Http\Requests\Vendors\StoreVendorRequest;
use App\Http\Requests\Vendors\UpdateVendorRequest;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 20 — Vendors. Shared pool (no company scope). 4 detail tabs;
 * the Expenses tab populates in Phase 6.
 */
class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('vendors.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $vendors = Vendor::query()
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->value();
                $q->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%")
                    ->orWhere('nif', 'like', "%{$term}%"));
            })
            ->when($request->string('status')->value() === 'active', fn (Builder $q) => $q->where('active', true))
            ->when($request->string('status')->value() === 'inactive', fn (Builder $q) => $q->where('active', false))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Vendor $vendor): array => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'company_name' => $vendor->company_name,
                'nif' => $vendor->nif,
                'phone' => $vendor->phone,
                'email' => $vendor->email,
                'city' => $vendor->city,
                'active' => $vendor->active,
            ]);

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
            'filters' => $request->only(['search', 'status', 'per_page']),
            'can' => [
                'create' => Gate::allows('vendors.create'),
                'edit' => Gate::allows('vendors.edit'),
                'delete' => Gate::allows('vendors.delete'),
            ],
        ]);
    }

    public function show(Vendor $vendor): Response
    {
        Gate::authorize('vendors.view');

        return Inertia::render('Vendors/Detail', [
            'vendor' => $vendor->only([
                'id', 'name', 'company_name', 'nif', 'phone', 'alternate_phone',
                'email', 'address', 'area', 'city', 'country', 'postal_code',
                'bank_account', 'payment_terms', 'active', 'notes',
            ]),
            'contacts' => $vendor->contacts()->get(['id', 'name', 'position', 'phone', 'email', 'is_primary', 'notes']),
            'paymentTerms' => $vendor->paymentTerms()->get(['id', 'name', 'days', 'discount_percentage', 'discount_days', 'is_default', 'description']),
            // Gastos tab — vendor is shared, but expenses are company-owned, so
            // the tenant scope shows only THIS company's expenses to the vendor.
            'expenses' => Gate::allows('expenses.view') ? $this->vendorExpenses($vendor) : [],
            'canViewExpenses' => Gate::allows('expenses.view'),
            'can' => [
                'edit' => Gate::allows('vendors.edit'),
                'delete' => Gate::allows('vendors.delete'),
            ],
        ]);
    }

    /**
     * This company's expenses to the vendor (tenant-scoped), newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function vendorExpenses(Vendor $vendor): array
    {
        return Expense::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('date')
            ->get()
            ->map(fn (Expense $e): array => [
                'id' => $e->id,
                'number' => $e->number,
                'date' => $e->date->toDateString(),
                'total' => (float) $e->total,
                'status' => $e->payment_status->value,
            ])
            ->all();
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        Vendor::query()->create($request->validated());

        return back()->with('success', __('ui.vendors.saved'));
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($request->validated());

        return back()->with('success', __('ui.vendors.saved'));
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        Gate::authorize('vendors.delete');

        $vendor->delete();

        return redirect()->route('vendors.index')->with('success', __('ui.vendors.deleted'));
    }

    public function storeContact(Request $request, Vendor $vendor): RedirectResponse
    {
        Gate::authorize('vendors.edit');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $vendor->contacts()->create($validated);

        return back()->with('success', __('ui.vendors.contact_saved'));
    }

    public function storePaymentTerm(Request $request, Vendor $vendor): RedirectResponse
    {
        Gate::authorize('vendors.edit');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_default' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $vendor->paymentTerms()->create($validated);

        return back()->with('success', __('ui.vendors.term_saved'));
    }
}
