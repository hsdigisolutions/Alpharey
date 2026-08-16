<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EquipmentItem;
use App\Models\Leave;
use App\Models\Project;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Models\Vehicle;
use App\Models\VehicleFine;
use App\Services\Workers\WorkerAccountService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Phase 6 QA fixes — one regression test per fix. See CLAUDE.md.
 */
function grantPerm(User $user, Company $company, string $module, array $actions): void
{
    UserModulePermission::query()->create(array_merge([
        'user_id' => $user->id, 'company_id' => $company->id, 'module' => $module,
    ], $actions));
}

// Fix 1 — the wage-visibility gate (canSeeWages) is independent of project-edit
// rights. A prop-shadowing const previously made every money column key off
// projects.edit; the server contract that must hold is that canSeeWages
// (payroll.view || employees.edit) and can.edit are decoupled.
it('Fix 1: ships canSeeWages true and can.edit false for a payroll-view-only user', function (): void {
    $company = Company::factory()->create();
    $project = Project::factory()->create(['company_id' => $company->id]);
    $manager = User::factory()->forCompany($company)->create(); // Manager role by default

    grantPerm($manager, $company, 'projects', ['can_view' => true]); // NOT can_edit
    grantPerm($manager, $company, 'payroll', ['can_view' => true]);  // wage visibility

    $this->actingAs($manager)->get("/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeWages', true)
            ->where('can.edit', false));
});

// Fix 2 — replace/updateMetadata must enforce the company-document admin guard,
// like download/destroy/exempt. A Manager granted documents.edit (for employee
// paperwork) must NOT be able to mutate the company's own compliance files.
it('Fix 2: a manager with documents.edit cannot replace or rewrite a company document', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();

    $this->actingAs($admin)->post('/documents', [
        'entity_type' => 'company', 'entity_id' => $company->id,
        'category' => 'company', 'type_key' => 'poliza_rc',
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $doc = Document::query()->where('company_id', $company->id)->firstOrFail();

    $manager = User::factory()->forCompany($company)->create();
    grantPerm($manager, $company, 'documents', ['can_view' => true, 'can_edit' => true, 'can_download' => true]);

    $this->actingAs($manager)
        ->post("/documents/{$doc->id}/replace", ['file' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf')])
        ->assertForbidden();

    $this->actingAs($manager)
        ->patch("/documents/{$doc->id}/metadata", ['metadata' => []])
        ->assertForbidden();

    // An admin of the same company is still allowed (the guard doesn't over-block).
    $this->actingAs($admin)
        ->patch("/documents/{$doc->id}/metadata", ['metadata' => []])
        ->assertRedirect()->assertSessionHasNoErrors();
});

// Fix 3 — the permission-matrix index must not resolve a foreign ?user= id, or
// it leaks that company's manager permission grid into the props.
it('Fix 3: an admin cannot inspect a foreign-company user permission grid', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $adminA = User::factory()->companyAdmin()->forCompany($companyA)->create();
    $managerB = User::factory()->forCompany($companyB)->create();
    $managerA = User::factory()->forCompany($companyA)->create();

    $this->actingAs($adminA)->get("/admin/permissions?user={$managerB->id}")->assertNotFound();
    $this->actingAs($adminA)->get("/admin/permissions?user={$managerA->id}")->assertOk();
});

// Fix 4C — leave attachment download audits the context in the description slot,
// not the old_values slot.
it('Fix 4C: leave attachment download logs a description, not old_values', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $path = UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf')->store('leave-attachments', 'local');
    $leave = Leave::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    $leave->forceFill(['file_path' => $path, 'original_name' => 'cert.pdf'])->save();

    $this->actingAs($admin)->get("/leave/{$leave->id}/attachment")->assertOk();

    $log = AuditLog::query()->where('action', 'viewed')->where('module', 'leave_management')
        ->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->description)->toBe('Leave attachment')
        ->and($log->old_values)->toBeNull();
});

// Fix 4D — a consumable item cannot be issued to a worker or assigned to a
// project (UI-only rule now enforced server-side).
it('Fix 4D: a consumable cannot be issued or assigned to a project', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();
    $item = EquipmentItem::factory()->create(['company_id' => $company->id, 'item_type' => 'consumable']);
    $employee = Employee::factory()->forCompany($company)->create();
    $project = Project::factory()->create(['company_id' => $company->id]);

    $this->actingAs($admin)->post("/inventory/items/{$item->id}/issue", [
        'employee_id' => $employee->id, 'issued_quantity' => 1,
    ])->assertSessionHasErrors('item');

    $this->actingAs($admin)->post("/inventory/items/{$item->id}/assign", [
        'project_id' => $project->id, 'quantity' => 1, 'start_date' => '2026-08-16',
    ])->assertSessionHasErrors('item');
});

// Policy 1 — a worker never sees money: the vehicle-fine payload must carry no
// euro amount, only that a fine exists with its date/status.
it('Policy 1: the worker vehicle payload never carries a fine amount', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->privacyAcknowledged()
        ->create(['can_use_vehicles' => true]);

    $admin = User::factory()->companyAdmin()->forCompany($company)->create();
    $this->actingAs($admin);
    app(WorkerAccountService::class)->grant($employee, 'obrero@example.com', 'site-pass-123');
    auth()->logout();
    $worker = $employee->fresh()->user;

    $vehicle = Vehicle::factory()->create(['company_id' => $company->id]);
    VehicleFine::factory()->create([
        'company_id' => $company->id, 'vehicle_id' => $vehicle->id, 'employee_id' => $employee->id,
        'fine_date' => '2026-08-10', 'amount' => '150', 'description' => 'Exceso de velocidad',
    ]);

    $this->actingAs($worker)->get('/worker/vehicles')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('fines', 1)
            ->where('fines.0.description', 'Exceso de velocidad')
            ->missing('fines.0.amount'));
});
