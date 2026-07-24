<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * User management within the active company (Permission Matrix screen).
 * Company Admins manage custom users of their own company; the Super
 * Admin additionally creates/edits Company Admins (REQUIREMENTS.md §4).
 */
class UserController extends Controller
{
    use ResolvesCompanyContext;

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $companyId = $this->contextCompanyId();

        DB::transaction(function () use ($request, $companyId): void {
            $user = User::query()->create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'role' => $request->validated('role'),
                'locale' => $request->validated('locale'),
                'active' => true,
                // Always the active company context — never request input (Rule 1)
                'company_id' => $companyId,
            ]);

            // The primary company is always mirrored on the user_company
            // pivot — "sees only assigned companies" holds from day one.
            DB::table('user_company')->insert([
                'user_id' => $user->id,
                'company_id' => $companyId,
                'assigned_by' => $request->user()?->id,
                'created_at' => now(),
            ]);
        });

        return back()->with('success', __('ui.permissions.user_saved'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        // Nobody edits themselves through the admin panel (lockout/escalation guard)
        abort_if($actor !== null && $actor->id === $user->id, 422, 'Use your own profile settings.');

        if ($user->role === UserRole::SuperAdmin) {
            // Super Admins have no company — they sit outside the tenant scope.
            // Only another SA may edit them; the company-context guard is irrelevant.
            abort_unless($actor !== null && $actor->isSuperAdmin(), 403);
        } elseif ($actor !== null && $actor->isSuperAdmin()) {
            // The SA directory lists every company's users, so an SA edits any
            // of them regardless of which company they happen to be browsing —
            // requiring a primary-company match here 404'd the save for any
            // multi-company user whose primary sat elsewhere.
        } else {
            $companyId = $this->contextCompanyId();
            // Primary OR pivot-assigned into the admin's company — the same
            // membership rule as the Permission Matrix.
            abort_unless($user->isAssignedToCompany($companyId), 404);
            // Admins cannot modify other admins — Super Admin only
            abort_if($user->role !== UserRole::Manager, 403);
        }

        $data = [
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'role' => $request->validated('role'),
            'locale' => $request->validated('locale'),
            'active' => (bool) $request->validated('active'),
        ];

        $password = $request->validated('password');

        if (is_string($password) && $password !== '') {
            $data['password'] = $password;
        }

        $user->update($data);

        return back()->with('success', __('ui.permissions.user_saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        // Cannot delete yourself
        abort_if($actor !== null && $actor->id === $user->id, 422, 'Cannot delete yourself.');
        // Super Admin accounts are never hard-deleted — deactivate instead
        abort_if($user->isSuperAdmin(), 403, 'Super Admin accounts cannot be deleted. Deactivate them instead.');
        // Workers are managed via their employee record, not here
        abort_if($user->isWorker(), 403, 'Worker accounts are managed via the employee record.');

        if ($actor === null || ! $actor->isSuperAdmin()) {
            $companyId = $this->contextCompanyId();
            abort_unless($user->isAssignedToCompany($companyId), 404);
            abort_if($user->role !== UserRole::Manager, 403);
        }

        // Capture the audit entry before the row disappears
        app(AuditLogger::class)->log('deleted', $user);
        $user->delete();

        return back()->with('success', __('ui.permissions.user_deleted'));
    }
}
