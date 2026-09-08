<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyCard;
use App\Models\Expense;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company payment cards (Settings → Tarjetas de empresa). Simple inline CRUD,
 * scoped to the acting company by the CompanyCard tenancy trait (a cross-company
 * id 404s on route binding). Admin-only, mirroring the other Settings writes.
 *
 * Only a label + the last four digits are stored — never a full PAN
 * (SECURITY.md). A card used by any expense cannot be hard-deleted (it carries
 * history) — it is deactivated instead, which drops it from the expense-form
 * dropdown while existing expenses keep their reference.
 */
class CompanyCardController extends Controller
{
    private function authorizeAdmin(Request $request): int
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);

        $companyId = app(CurrentCompany::class)->id();
        abort_if($companyId === null, 403);

        return $companyId;
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->authorizeAdmin($request);

        $data = $request->validate([
            'label' => [
                'required', 'string', 'max:100',
                Rule::unique('company_cards', 'label')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'last_four' => ['nullable', 'digits:4'],
        ]);

        // company_id is set by the tenancy trait from the active company.
        CompanyCard::create([
            'label' => $data['label'],
            'last_four' => $data['last_four'] ?? null,
            'active' => true,
        ]);

        return back()->with('success', __('ui.settings.card_saved'));
    }

    public function update(Request $request, CompanyCard $companyCard): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'label' => [
                'required', 'string', 'max:100',
                Rule::unique('company_cards', 'label')
                    ->where(fn ($q) => $q->where('company_id', $companyCard->company_id))
                    ->ignore($companyCard->id),
            ],
            'last_four' => ['nullable', 'digits:4'],
            'active' => ['required', 'boolean'],
        ]);

        $companyCard->update($data);

        return back()->with('success', __('ui.settings.card_saved'));
    }

    public function destroy(Request $request, CompanyCard $companyCard): RedirectResponse
    {
        $this->authorizeAdmin($request);

        // A card used by any expense carries history — refuse the delete and
        // deactivate instead (preserves the historical expense link).
        if (Expense::query()->where('company_card_id', $companyCard->id)->exists()) {
            throw ValidationException::withMessages([
                'card' => __('ui.settings.card_in_use'),
            ]);
        }

        $companyCard->delete();

        return back()->with('success', __('ui.settings.card_deleted'));
    }
}
