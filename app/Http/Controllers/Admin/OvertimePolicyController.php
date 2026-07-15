<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OvertimePolicyType;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Controllers\Controller;
use App\Models\OvertimePolicy;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Overtime policies (Screen 26). Admins manage the policies
 * that price attendance overtime. Company-scoped like all admin data.
 */
class OvertimePolicyController extends Controller
{
    use ResolvesCompanyContext;

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->contextCompanyId();

        $data = $this->validated($request);

        $policy = new OvertimePolicy($data);
        $policy->company_id = app(CurrentCompany::class)->id();
        $policy->save();

        return back()->with('success', __('ui.settings.saved'));
    }

    public function update(Request $request, OvertimePolicy $overtime_policy): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $overtime_policy->update($this->validated($request));

        return back()->with('success', __('ui.settings.saved'));
    }

    public function destroy(Request $request, OvertimePolicy $overtime_policy): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $overtime_policy->delete();

        return back()->with('success', __('ui.settings.saved'));
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(OvertimePolicyType::class)],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'daily_threshold_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'accumulate_hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
