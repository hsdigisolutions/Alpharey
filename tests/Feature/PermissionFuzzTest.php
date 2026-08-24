<?php

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Support\Facades\Gate;

/**
 * SECURITY.md §9 gate item 3 — the permission fuzz suite.
 *
 * Rather than hand-pick a few cases, this walks EVERY Module × PermissionAction
 * (18 × 8 = 144 abilities) against every role, so a new module or action can
 * never quietly ship without its access rules being exercised. The invariants
 * it pins are the whole authorisation contract (SECURITY.md §3):
 *
 *   - an inactive user is denied everything, whatever their role
 *   - a Super Admin is allowed everything (Gate::before)
 *   - a Company Admin is allowed everything within their company
 *   - a custom user is denied by default and allowed ONLY the exact abilities
 *     granted on user_module_permissions — one grant never leaks to another
 *   - a grant made for one company does not apply when the user has no company
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
});

/** Every ability name, e.g. "employees.view". */
function everyAbility(): array
{
    $abilities = [];

    foreach (Module::cases() as $module) {
        foreach (PermissionAction::cases() as $action) {
            $abilities[] = $module->value.'.'.$action->value;
        }
    }

    return $abilities;
}

it('denies every ability to an inactive user, whatever the role', function (UserRole $role): void {
    $user = User::factory()->create([
        'role' => $role,
        'active' => false,
        'company_id' => $role === UserRole::SuperAdmin ? null : $this->company->id,
    ]);

    foreach (everyAbility() as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse("inactive {$role->value} should be denied {$ability}");
    }
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin' => [UserRole::Admin],
    'manager' => [UserRole::Manager],
    'worker' => [UserRole::Worker],
]);

it('denies every ability to a worker — even with a stray permission row', function (): void {
    $worker = User::factory()->create([
        'role' => UserRole::Worker,
        'active' => true,
        'company_id' => $this->company->id,
    ]);

    // A matrix row created for a worker by mistake must still grant nothing:
    // ModulePermissions refuses the role before it ever reads the table.
    UserModulePermission::query()->create([
        'user_id' => $worker->id,
        'company_id' => $this->company->id,
        'module' => Module::Employees->value,
        'can_view' => true,
        'granted_by' => $worker->id,
    ]);

    foreach (everyAbility() as $ability) {
        expect(Gate::forUser($worker)->allows($ability))->toBeFalse("worker should be denied {$ability}");
    }
});

it('allows every ability to an active Super Admin', function (): void {
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'active' => true, 'company_id' => null]);

    foreach (everyAbility() as $ability) {
        expect(Gate::forUser($sa)->allows($ability))->toBeTrue("super admin should be allowed {$ability}");
    }
});

it('allows every ability to a Company Admin within their company', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'active' => true, 'company_id' => $this->company->id]);

    foreach (everyAbility() as $ability) {
        expect(Gate::forUser($admin)->allows($ability))->toBeTrue("company admin should be allowed {$ability}");
    }
});

it('denies every ability to a custom user with no grants', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'active' => true, 'company_id' => $this->company->id]);

    foreach (everyAbility() as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse("ungranted custom user should be denied {$ability}");
    }
});

it('grants a custom user EXACTLY one ability and nothing else', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'active' => true, 'company_id' => $this->company->id]);

    // Grant only employees.view.
    UserModulePermission::query()->create([
        'user_id' => $user->id,
        'company_id' => $this->company->id,
        'module' => Module::Employees->value,
        'can_view' => true,
        'granted_by' => $user->id,
    ]);

    foreach (everyAbility() as $ability) {
        $expected = $ability === 'employees.view';
        expect(Gate::forUser($user)->allows($ability))->toBe($expected, "custom user with only employees.view — {$ability}");
    }
});

it('does not let one action grant imply a sibling action on the same module', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'active' => true, 'company_id' => $this->company->id]);

    // View on payroll must NOT confer edit/approve/export/download on payroll.
    UserModulePermission::query()->create([
        'user_id' => $user->id,
        'company_id' => $this->company->id,
        'module' => Module::Payroll->value,
        'can_view' => true,
        'granted_by' => $user->id,
    ]);

    expect(Gate::forUser($user)->allows('payroll.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('payroll.edit'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('payroll.approve'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('payroll.export'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('payroll.download'))->toBeFalse();
});

it('ignores a grant when the custom user has no company', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'active' => true, 'company_id' => null]);

    // A dangling grant referencing a company the user is not in.
    UserModulePermission::query()->create([
        'user_id' => $user->id,
        'company_id' => $this->company->id,
        'module' => Module::Employees->value,
        'can_view' => true,
        'granted_by' => $user->id,
    ]);

    // company_id is null → allows() short-circuits to false (SECURITY.md §3).
    expect(Gate::forUser($user)->allows('employees.view'))->toBeFalse();
});

it('registers a gate for every module and action (no ability left undefined)', function (): void {
    // If a Module or PermissionAction is added without wiring the gate, the
    // ability resolves to Gate's "undefined = deny" and a Super Admin (who must
    // pass everything) would be silently denied it. This catches that at the
    // source.
    $abilities = everyAbility();

    expect($abilities)->toHaveCount(count(Module::cases()) * count(PermissionAction::cases()))
        ->and(count($abilities))->toBe(180); // 20 modules × 9 actions

    foreach ($abilities as $ability) {
        expect(Gate::has($ability))->toBeTrue("ability {$ability} must be a defined gate");
    }
});

it('offers no dead toggle — every applicable ability is checked in the code', function (): void {
    // Read every app + frontend source file once; each matrix-applicable ability
    // must appear somewhere (a Gate::allows/authorize, can:, or a Vue can.* prop).
    $haystack = '';
    foreach ([base_path('app'), base_path('resources')] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), ['php', 'vue'], true)) {
                $haystack .= file_get_contents($file->getPathname());
            }
        }
    }

    foreach (Module::cases() as $module) {
        foreach ($module->actions() as $action) {
            $ability = $module->value.'.'.$action->value;
            expect(str_contains($haystack, $ability))
                ->toBeTrue("the permission matrix offers '{$ability}' but no code checks it (dead toggle)");
        }
    }
});
