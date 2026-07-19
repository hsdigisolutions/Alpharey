<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePermissionsRequest;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 17 — Permission Matrix (Super Admin + Company Admin).
 * Writes user_module_permissions rows; the Gate engine reads them
 * uncached, so changes take effect immediately.
 */
class PermissionMatrixController extends Controller
{
    use ResolvesCompanyContext;

    public function index(Request $request): Response
    {
        $companyId = $this->contextCompanyId();

        $actor = $request->user();

        // A Super Admin administers everyone — including other companies' users
        // and other Super Admins — so scoping the list to the selected company
        // hid most of the directory from them. Company Admins stay confined to
        // their own company (tenancy, dev-skill Rule 1).
        $users = User::query()
            ->when(
                $actor !== null && ! $actor->isSuperAdmin(),
                fn ($q) => $q->where('company_id', $companyId),
            )
            ->with('company:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'active', 'company_id'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'active' => $user->active,
                // Shown so a Super Admin can tell two same-named users apart.
                'company' => $user->company?->name,
                // Only custom users have per-module rows; admins bypass the matrix
                'editable' => $user->role === UserRole::User,
            ]);

        $selectedId = $request->integer('user') ?: null;
        $copyFromId = $request->integer('copy_from') ?: null;

        return Inertia::render('Admin/Permissions', [
            'users' => $users,
            'matrix' => $this->matrixDefinition(),
            'selectedUser' => $selectedId,
            // A user's grants belong to THEIR company, which is not necessarily
            // the one the Super Admin is currently browsing.
            'permissions' => $selectedId === null ? [] : $this->permissionsFor($this->companyOfUser($selectedId, $companyId), $selectedId),
            'copySourcePermissions' => $copyFromId === null ? null : $this->permissionsFor($this->companyOfUser($copyFromId, $companyId), $copyFromId),
        ]);
    }

    /**
     * The company whose grants apply to a user. For a Super Admin browsing
     * another company's user that is the USER's company; for everyone else it
     * can only ever be their own (the caller already confined the list).
     */
    private function companyOfUser(int $userId, int $fallbackCompanyId): int
    {
        $actor = request()->user();

        if ($actor === null || ! $actor->isSuperAdmin()) {
            return $fallbackCompanyId;
        }

        return User::query()->whereKey($userId)->value('company_id') ?? $fallbackCompanyId;
    }

    public function update(SavePermissionsRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        // A Super Admin may administer any company's user; everyone else is
        // confined to their own — 404, never 403, so existence is not leaked.
        if ($actor !== null && $actor->isSuperAdmin()) {
            abort_if($user->company_id === null, 422, 'This user belongs to no company.');
            $companyId = $user->company_id;
        } else {
            $companyId = $this->contextCompanyId();
            abort_unless($user->company_id === $companyId, 404);
        }

        // Admin roles bypass the matrix; only custom users carry rows.
        abort_unless($user->role === UserRole::User, 422, 'Admins bypass the permission matrix.');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->validated('permissions');

        DB::transaction(function () use ($rows, $user, $companyId, $request): void {
            foreach ($rows as $row) {
                $module = Module::from($row['module']);
                $applicable = collect($module->actions())->map(fn (PermissionAction $a) => $a->column());

                $values = collect(PermissionAction::cases())
                    ->mapWithKeys(fn (PermissionAction $action) => [
                        // Non-applicable actions are forced off, whatever the client sent
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

        return back()->with('success', __('ui.permissions.saved'));
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
