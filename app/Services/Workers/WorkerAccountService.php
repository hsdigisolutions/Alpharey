<?php

namespace App\Services\Workers;

use App\Enums\UserRole;
use App\Models\Employee;
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
