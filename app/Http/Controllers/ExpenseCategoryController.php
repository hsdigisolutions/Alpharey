<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Custom expense categories (the legacy "Categorías" the admin manages). A
 * category with a null company_id is a group-wide default; an admin-created one
 * is scoped to the acting company. Referenced categories are never hard-deleted
 * (they carry history) — they are deactivated instead.
 */
class ExpenseCategoryController extends Controller
{
    use ResolvesCompanyContext;

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('expenses.edit');

        $companyId = $this->contextCompanyId();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('expense_categories', 'name')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ]);

        ExpenseCategory::create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'active' => true,
        ]);

        return back()->with('success', __('ui.expenses.category_saved'));
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        Gate::authorize('expenses.edit');

        // A group-wide default (null company_id) is not editable by a company —
        // only its own rows are.
        abort_if($expenseCategory->company_id !== $this->contextCompanyId(), 404);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('expense_categories', 'name')
                    ->where(fn ($q) => $q->where('company_id', $expenseCategory->company_id))
                    ->ignore($expenseCategory->id),
            ],
            'active' => ['required', 'boolean'],
        ]);

        $expenseCategory->update($data);

        return back()->with('success', __('ui.expenses.category_saved'));
    }

    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        Gate::authorize('expenses.edit');

        abort_if($expenseCategory->company_id !== $this->contextCompanyId(), 404);

        // Referenced categories carry history — deactivate rather than delete so
        // existing expenses keep their label.
        if ($expenseCategory->expenses()->exists()) {
            $expenseCategory->update(['active' => false]);

            return back()->with('success', __('ui.expenses.category_deactivated'));
        }

        $expenseCategory->delete();

        return back()->with('success', __('ui.expenses.category_deleted'));
    }
}
