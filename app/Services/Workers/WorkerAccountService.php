<?php

namespace App\Services\Workers;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and revoking the login an employee uses for the mobile PWA.
 *
 * A worker account is not a normal user account: it is meaningless on its own,
 * it belongs to exactly one employee, and it can reach nothing but that
 * employee's own attendance. So it is created HERE from the employee record
 * rather than through the admin user forms — which is also why UserRole
 * deliberately leaves Worker out of assignable().
 */
class WorkerAccountService
{
    /**
     * Give an employee app access, or reset the password of the login they
     * already have.
     */
    public function grant(Employee $employee, string $email, string $password): User
    {
        return DB::transaction(function () use ($employee, $email, $password): User {
            $existing = $employee->user;

            if ($existing !== null) {
                $existing->forceFill([
                    'email' => $email,
                    'password' => $password,
                    'active' => true,
                ])->save();

                return $existing;
            }

            // Access is revoked by DEACTIVATING + unlinking the login, never
            // deleting it (see revoke() — the audit trail and the attendance
            // rows it authored must survive). So re-granting to a worker whose
            // access was previously withdrawn finds an old, deactivated login
            // still holding this email. Reclaim it — reactivate, re-point at
            // this employee's company, reset the password — rather than trying
            // to create a duplicate (which User's unique email would reject as
            // "email already in use", the exact production block for VE-0003).
            $reclaimed = $this->reclaimableLoginFor($email);

            if ($reclaimed !== null) {
                $reclaimed->forceFill([
                    'name' => $employee->full_name,
                    'password' => $password,
                    // Follows the employee — a transferred worker's old login
                    // was pointed at the previous company; re-home it here.
                    'company_id' => $employee->company_id,
                    'active' => true,
                ])->save();

                $employee->user_id = $reclaimed->id;
                $employee->save();

                return $reclaimed;
            }

            $this->assertEmailIsFree($email);

            $user = User::query()->create([
                'name' => $employee->full_name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Worker,
                // The worker belongs to the employee's company, never the
                // acting company — an admin of one company must not be able to
                // mint a login pointed at another's workforce.
                'company_id' => $employee->company_id,
                'locale' => 'es',
                'active' => true,
            ]);

            $employee->user_id = $user->id;
            $employee->save();

            return $user;
        });
    }

    /**
     * An old worker login that this grant may safely take over: a deactivated
     * Worker account holding this email that no employee is linked to any more
     * (its access was revoked). Anything else — an active account, a non-worker
     * account, or a worker still linked to another employee — is a genuine
     * collision and must fall through to assertEmailIsFree(), which rejects it.
     */
    private function reclaimableLoginFor(string $email): ?User
    {
        $candidate = User::query()
            ->where('email', $email)
            ->where('role', UserRole::Worker->value)
            ->where('active', false)
            ->first();

        if ($candidate === null) {
            return null;
        }

        // Never steal a login that is still another employee's. Only the tenant
        // scope is dropped (a worker's employee may live in another company);
        // trashed employees are included ON PURPOSE — a login a soft-deleted
        // worker still points at is not orphaned, so it must not be reclaimed.
        $stillLinked = Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('user_id', $candidate->id)
            ->exists();

        return $stillLinked ? null : $candidate;
    }

    /**
     * Withdraw app access.
     *
     * The login is DEACTIVATED and unlinked rather than deleted: its audit
     * trail, and the attendance rows it created, must survive. A deleted user
     * would leave those rows pointing at nothing.
     */
    public function revoke(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $user = $employee->user;

            $employee->user_id = null;
            $employee->save();

            $user?->forceFill(['active' => false])->save();
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertEmailIsFree(string $email): void
    {
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('ui.worker_access.email_taken'),
            ]);
        }
    }
}
