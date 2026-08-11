<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\NotificationRoleRule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single authority for "should this role receive this notification type"
 * (Screen 26). Everything that sends a Phase-8 notification asks here first,
 * so the Settings matrix genuinely controls delivery rather than being
 * decorative.
 *
 * A missing rule falls back to the type's coded default
 * (NotificationType::defaultRoles), so an empty table behaves exactly as the
 * system did before the matrix existed — the screen only ever narrows or
 * widens from a sensible baseline.
 */
class NotificationRules
{
    /**
     * Does a role receive this notification type?
     */
    public function allows(NotificationType $type, UserRole $role): bool
    {
        $rule = NotificationRoleRule::query()
            ->where('notification_type', $type->value)
            ->where('role', $role->value)
            ->first();

        if ($rule !== null) {
            return $rule->enabled;
        }

        return in_array($role, $type->defaultRoles(), true);
    }

    /**
     * The users who should receive a type within one company — the recipient
     * list a notifier can hand straight to Notification::send().
     *
     * @return Collection<int, User>
     */
    public function recipients(NotificationType $type, int $companyId): Collection
    {
        $roles = collect(UserRole::cases())
            ->filter(fn (UserRole $role): bool => $this->allows($type, $role))
            ->map(fn (UserRole $role): string => $role->value)
            ->all();

        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->where('active', true)
            ->whereIn('role', $roles)
            ->where(function ($q) use ($companyId): void {
                // Admins/Managers of THIS company — primary or assigned into
                // it via the user_company pivot — plus Super Admins (who have
                // no company and see everything).
                $q->where('company_id', $companyId)
                    ->orWhereHas('companies', fn ($c) => $c->where('companies.id', $companyId))
                    ->orWhere('role', UserRole::SuperAdmin->value);
            })
            ->get();
    }

    /**
     * The full matrix for the Settings screen: every type × every role, with
     * the effective enabled state (stored rule or coded default).
     *
     * @return list<array{type: string, roles: array<string, bool>}>
     */
    public function matrix(): array
    {
        $stored = NotificationRoleRule::query()->get()
            ->keyBy(fn (NotificationRoleRule $r): string => "{$r->notification_type}:{$r->role}");

        $out = [];

        foreach (NotificationType::cases() as $type) {
            // Worker-direct types go to a specific user, not a role — not tunable.
            if ($type->isWorkerDirect()) {
                continue;
            }

            $roles = [];

            foreach (UserRole::cases() as $role) {
                $key = "{$type->value}:{$role->value}";
                $roles[$role->value] = $stored->has($key)
                    ? $stored[$key]->enabled
                    : in_array($role, $type->defaultRoles(), true);
            }

            $out[] = ['type' => $type->value, 'roles' => $roles];
        }

        return $out;
    }

    /**
     * Persist the matrix from the Settings screen. Input is untyped request
     * data, so every key is treated as possibly-absent.
     *
     * @param  array<int, array<string, mixed>>  $matrix
     */
    public function save(array $matrix): void
    {
        foreach ($matrix as $row) {
            $type = NotificationType::tryFrom(is_string($row['type'] ?? null) ? $row['type'] : '');

            if ($type === null) {
                continue;
            }

            $roles = is_array($row['roles'] ?? null) ? $row['roles'] : [];

            foreach ($roles as $role => $enabled) {
                if (UserRole::tryFrom((string) $role) === null) {
                    continue;
                }

                NotificationRoleRule::query()->updateOrCreate(
                    ['notification_type' => $type->value, 'role' => (string) $role],
                    ['enabled' => (bool) $enabled],
                );
            }
        }
    }
}
