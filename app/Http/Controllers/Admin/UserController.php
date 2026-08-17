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

        $newRole = (string) $request->validated('role');

        // Never demote the LAST Super Admin — the group must always keep one.
        if ($user->isSuperAdmin() && $newRole !== UserRole::SuperAdmin->value) {
            abort_if(
                User::query()->where('role', UserRole::SuperAdmin)->count() <= 1,
                422,
                'At least one Super Admin must remain.',
            );
        }

        $data = [
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'role' => $newRole,
            'locale' => $request->validated('locale'),
            'active' => (bool) $request->validated('active'),
        ];

        // Role transition side-effects (a Super Admin has NO company; a company
        // role MUST have one). Keep users.company_id + the pivot in step.
        if ($newRole === UserRole::SuperAdmin->value) {
            $data['company_id'] = null; // promoted to SA → drops out of every company
        } elseif ($user->isSuperAdmin() && $request->filled('company_id')) {
            $data['company_id'] = (int) $request->validated('company_id'); // demoted → lands in one
        }

        $password = $request->validated('password');

        if (is_string($password) && $password !== '') {
            $data['password'] = $password;
        }

        DB::transaction(function () use ($user, $data, $newRole, $actor): void {
            $user->update($data);

            if ($newRole === UserRole::SuperAdmin->value) {
                DB::table('user_company')->where('user_id', $user->id)->delete();
            } elseif (array_key_exists('company_id', $data) && $data['company_id'] !== null) {
                DB::table('user_company')->updateOrInsert(
                    ['user_id' => $user->id, 'company_id' => $data['company_id']],
                    ['assigned_by' => $actor?->id, 'created_at' => now()],
                );
            }
        });

        return back()->with('success', __('ui.permissions.user_saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        // Cannot delete yourself
        abort_if($actor !== null && $actor->id === $user->id, 422, 'Cannot delete yourself.');
        // Workers are managed via their employee record, not here
        abort_if($user->isWorker(), 403, 'Worker accounts are managed via the employee record.');

        if ($user->isSuperAdmin()) {
            // Only another Super Admin may delete a Super Admin, and never the
            // last one — the group must always keep at least one.
            abort_unless($actor !== null && $actor->isSuperAdmin(), 403);
            abort_if(
                User::query()->where('role', UserRole::SuperAdmin)->count() <= 1,
                422,
                'At least one Super Admin must remain.',
            );
        } elseif ($actor === null || ! $actor->isSuperAdmin()) {
            // A company admin may only delete Managers of their own company.
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
