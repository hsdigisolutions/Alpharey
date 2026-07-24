<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePermissionsRequest;
use App\Models\Company;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 17 — Permission Matrix (Super Admin + Admin).
 *
 * Responsibilities:
 *  - User list with assigned companies
 *  - Assign / remove companies for an individual user
 *  - Module-permission toggle grid for Manager-role users
 *  - 2FA reset and password-reset levers (Super Admin only)
 *
 * Writes user_module_permissions rows; the Gate engine reads them uncached
 * so changes take effect immediately.
 */
class PermissionMatrixController extends Controller
{
    use ResolvesCompanyContext;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $companyId = $this->contextCompanyId();

        $actor = $request->user();

        // Super Admin sees every user (cross-company directory). Admin sees
        // the users of their active company — primary OR assigned into it
        // via the user_company pivot (multi-company managers).
        $users = User::query()
            ->where('role', '!=', UserRole::Worker->value)
            ->when(
                $actor !== null && ! $actor->isSuperAdmin(),
                fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('company_id', $companyId)
                    ->orWhereHas('companies', fn ($c) => $c->where('companies.id', $companyId))),
            )
            ->with([
                'company:id,name',
                'companies:id,name',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'active', 'company_id', 'locale', 'password_reset_requested_at'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'active' => $user->active,
                'locale' => $user->locale,
                'company' => $user->company?->name,
                // All companies this user is assigned to (for the companies panel)
                'assigned_companies' => $user->companies->map(fn (Company $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                ])->values()->all(),
                // Only Managers have per-module rows; Admins bypass the matrix.
                'editable' => $user->role === UserRole::Manager,
                'password_reset_requested' => $user->password_reset_requested_at !== null,
            ]);

        // All companies available for assignment (SA sees all; an Admin only
        // the companies they are themselves assigned to)
        $availableCompanies = Company::query()
            ->when(
                $actor !== null && ! $actor->isSuperAdmin(),
                fn ($q) => $q->whereIn(
                    'id',
                    $actor->companies->pluck('id')->push($actor->company_id)->filter()->unique(),
                ),
            )
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c): array => ['id' => $c->id, 'name' => $c->name])
            ->all();

        $selectedId = $request->integer('user') ?: null;
        $copyFromId = $request->integer('copy_from') ?: null;

        return Inertia::render('Admin/Permissions', [
            'users' => $users,
            'matrix' => $this->matrixDefinition(),
            'availableCompanies' => $availableCompanies,
            'selectedUser' => $selectedId,
            'permissions' => $selectedId === null ? [] : $this->permissionsFor($this->companyOfUser($selectedId, $companyId), $selectedId),
            'copySourcePermissions' => $copyFromId === null ? null : $this->permissionsFor($this->companyOfUser($copyFromId, $companyId), $copyFromId),
        ]);
    }

    /**
     * The company whose module-permission rows apply to a user on this
     * screen. Rows are per (user, company): when the target is assigned to
     * the ACTIVE company (primary or pivot) the grid reads/writes that
     * company's rows — that is what lets an Admin grant a multi-company
     * Manager rights in a secondary company. Otherwise (a Super Admin
     * browsing a company the target is not part of) fall back to the
     * target's primary company.
     */
    private function companyOfUser(int $userId, int $contextCompanyId): int
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return $contextCompanyId;
        }

        return $user->isAssignedToCompany($contextCompanyId)
            ? $contextCompanyId
            : ($user->company_id ?? $contextCompanyId);
    }

    /**
     * Save the full module-permission grid for a Manager.
     */
    public function update(SavePermissionsRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($actor !== null && $actor->isSuperAdmin()) {
            // Rows land on the browsed company when the target is assigned to
            // it (that is how a secondary company's rights are granted);
            // otherwise on the target's own primary company.
            $selected = app(CurrentCompany::class)->id();
            $companyId = ($selected !== null && $user->isAssignedToCompany($selected))
                ? $selected
                : $user->company_id;
            abort_if($companyId === null, 422, 'This user belongs to no company.');
        } else {
            $companyId = $this->contextCompanyId();
            // Primary OR pivot-assigned into the admin's company — same
            // membership rule as the user list above.
            abort_unless($user->isAssignedToCompany($companyId), 404);
        }

        abort_unless($user->role === UserRole::Manager, 422, 'Only Manager-role users have per-module permissions.');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->validated('permissions');

        DB::transaction(function () use ($rows, $user, $companyId, $request): void {
            foreach ($rows as $row) {
                $module = Module::from($row['module']);
                $applicable = collect($module->actions())->map(fn (PermissionAction $a) => $a->column());

                $values = collect(PermissionAction::cases())
                    ->mapWithKeys(fn (PermissionAction $action) => [
                        $action->column() => $applicable->contains($action->column())
                            && (bool) ($row[$action->column()] ?? false),
                    ])
                    ->all();

                UserModulePermission::query()->updateOrCreate(
                    ['user_id' => $user->id, 'company_id' => $companyId, 'module' => $module->value],
                    $values + ['granted_by' => $request->user()?->id],
                );
            }
        });

        $this->audit->log('updated', $user, [], ['permissions_saved_for_company' => $companyId]);

        return back()->with('success', __('ui.permissions.saved'));
    }

    /**
     * Assign a company to a user (Super Admin or Admin within their own company).
     */
    public function assignCompany(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof User && ($actor->isSuperAdmin() || $actor->isAdmin()), 403);
        abort_if($user->isSuperAdmin(), 403, 'Super Admins are not bound to companies via the pivot.');
        // A worker's company comes from their employee record, never from here.
        abort_if($user->isWorker(), 403, 'Worker accounts belong to their employee record.');

        $companyId = (int) $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ])['company_id'];

        // Admins may only assign within companies they are themselves part of
        if (! $actor->isSuperAdmin()) {
            abort_unless($actor->isAssignedToCompany($companyId), 403);
        }

        DB::table('user_company')->insertOrIgnore([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'assigned_by' => $actor->id,
            'created_at' => now(),
        ]);

        // Set as primary company if the user has none yet
        if ($user->company_id === null) {
            $user->update(['company_id' => $companyId]);
        }

        $this->audit->log('updated', $user, [], ['company_assigned' => $companyId]);

        return back()->with('success', __('ui.permissions.company_assigned'));
    }

    /**
     * Remove a company assignment from a user.
     */
    public function removeCompany(Request $request, User $user, Company $company): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof User && ($actor->isSuperAdmin() || $actor->isAdmin()), 403);
        abort_if($user->isSuperAdmin(), 403);
        abort_if($user->isWorker(), 403);

        // Admins may only remove within companies they are themselves part of
        if (! $actor->isSuperAdmin()) {
            abort_unless($actor->isAssignedToCompany($company->id), 403);
        }

        DB::table('user_company')
            ->where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->delete();

        // If the removed company was the user's primary, update it
        if ($user->company_id === $company->id) {
            $next = DB::table('user_company')
                ->where('user_id', $user->id)
                ->value('company_id');

            $user->update(['company_id' => $next]);
        }

        $this->audit->log('updated', $user, [], ['company_removed' => $company->id]);

        return back()->with('success', __('ui.permissions.company_removed'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matrixDefinition(): array
    {
        return collect(Module::cases())->map(fn (Module $module): array => [
            'module' => $module->value,
            'actions' => collect(PermissionAction::cases())->mapWithKeys(fn (PermissionAction $action) => [
                $action->value => in_array($action, $module->actions(), true),
            ])->all(),
        ])->all();
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function permissionsFor(int $companyId, int $userId): array
    {
        return UserModulePermission::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->get()
            ->mapWithKeys(fn (UserModulePermission $row) => [
                $row->module => collect(PermissionAction::cases())->mapWithKeys(
                    fn (PermissionAction $action) => [$action->value => (bool) $row->getAttribute($action->column())],
                )->all(),
            ])
            ->all();
    }
}
