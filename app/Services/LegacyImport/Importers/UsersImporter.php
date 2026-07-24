<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\LegacyImport\AbstractImporter;
use Illuminate\Support\Facades\DB;

/**
 * Legacy users → new users (DATA_MIGRATION.md §3.2).
 *
 *  - legacy role super_admin/admin (RBAC roles or users.role column)
 *    → Super Admin (no company)
 *  - everything else (manager, accountant, viewer, team_leader)
 *    → custom user attached to Company 1, permissions set later by admins
 *  - bcrypt password hashes carry over (users keep their passwords)
 *  - email collisions are reported, never overwritten
 */
class UsersImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'users';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('users', null, 'No companies exist — run the seeder first.');

            return;
        }

        $adminRoleUserIds = $this->legacyAdminUserIds();

        $this->legacy('users')->orderBy('id')->chunk(200, function ($rows) use ($defaultCompanyId, $adminRoleUserIds): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                if (User::query()->where('email', $row->email)->exists()) {
                    $this->exception('users', $row->id, 'Email already exists in the new system', [
                        'email' => $row->email,
                    ]);

                    continue;
                }

                $isAdmin = in_array($row->id, $adminRoleUserIds, true)
                    || in_array($row->role ?? null, ['admin', 'super_admin'], true);

                $user = new User;
                $user->forceFill([
                    'name' => $row->name,
                    'email' => $row->email,
                    // Temporary random secret — replaced below by the legacy hash
                    'password' => bin2hex(random_bytes(20)),
                    'role' => $isAdmin ? UserRole::SuperAdmin : UserRole::Manager,
                    'company_id' => $isAdmin ? null : $defaultCompanyId,
                    'locale' => 'es',
                    'active' => ($row->status ?? 'active') === 'active',
                ])->save();

                // Carry the legacy bcrypt hash verbatim (users keep their
                // passwords). Base-query update bypasses the hashed cast,
                // which would reject hashes with a different cost setting.
                User::query()->whereKey($user->id)->update(['password' => $row->password]);

                // Mirror the primary company on the user_company pivot —
                // every company-bound user must appear there (the invariant
                // the Permission Matrix companies panel relies on).
                if (! $isAdmin) {
                    DB::table('user_company')->insertOrIgnore([
                        'user_id' => $user->id,
                        'company_id' => $defaultCompanyId,
                        'created_at' => now(),
                    ]);
                }

                $this->recordMapping($row->id, $user->id);
                $this->imported++;
            }
        });
    }

    /**
     * Users holding admin/super_admin via the legacy RBAC tables.
     *
     * @return list<int>
     */
    private function legacyAdminUserIds(): array
    {
        try {
            return $this->legacy('user_role')
                ->join('roles', 'roles.id', '=', 'user_role.role_id')
                ->whereIn('roles.name', ['admin', 'super_admin'])
                ->pluck('user_role.user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        } catch (\Throwable) {
            // RBAC tables absent in this dump — fall back to users.role only
            return [];
        }
    }
}
