<?php

namespace App\Http\Controllers;

use App\Enums\AdvanceStatus;
use App\Models\Advance;
use App\Models\AdvanceCategory;
use App\Rules\OwnCompanyEmployee;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Salary advances (Screen 12, lower section). Gated on the payroll module —
 * an advance is pay data, so the amount is encrypted and only exposed to
 * payroll.view holders.
 */
class AdvanceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('payroll.create');

        $validated = $request->validate([
            // Ownership is validity: an id from another company would deduct
            // from THAT company's payslip (payroll gathers per employee).
            'employee_id' => ['required', 'integer', new OwnCompanyEmployee],
            'advance_category_id' => ['nullable', 'integer', Rule::exists('advance_categories', 'id')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_date' => ['required', 'date'],
            'payroll_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $advance = new Advance($validated);
        $advance->company_id = app(CurrentCompany::class)->id();
        $advance->status = AdvanceStatus::Pending;
        $advance->save();

        return back()->with('success', __('ui.advances.saved'));
    }

    /**
     * Approve or reject. A deducted advance is settled and immutable — it has
     * already come off a payroll, so re-deciding it would silently change
     * money that was paid.
     */
    public function decide(Request $request, Advance $advance): RedirectResponse
    {
        Gate::authorize('payroll.approve');

        abort_if($advance->status === AdvanceStatus::Deducted, 422, 'Deducted advances cannot be changed.');

        $validated = $request->validate([
            'status' => ['required', Rule::in([AdvanceStatus::Approved->value, AdvanceStatus::Rejected->value])],
            'payroll_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $advance->status = AdvanceStatus::from($validated['status']);
        $advance->approved_by = Auth::id();
        $advance->approved_at = now();

        if (isset($validated['payroll_month'])) {
            $advance->payroll_month = $validated['payroll_month'];
        }

        $advance->save();

        return back()->with('success', __('ui.advances.decided'));
    }

    public function destroy(Advance $advance): RedirectResponse
    {
        Gate::authorize('payroll.edit');

        abort_if($advance->status === AdvanceStatus::Deducted, 422, 'Deducted advances cannot be removed.');

        $advance->delete();

        return back()->with('success', __('ui.advances.deleted'));
    }

    /**
     * Settings → advance categories.
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('payroll.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        AdvanceCategory::query()->create([
            'company_id' => app(CurrentCompany::class)->id(),
            'name' => $validated['name'],
            'active' => true,
        ]);

        return back()->with('success', __('ui.advances.category_saved'));
    }
}
