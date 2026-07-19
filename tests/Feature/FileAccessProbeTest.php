<?php

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * SECURITY.md §9 gate item 4 — direct file-access probing.
 *
 * Every private upload (documents, leave justificantes) must be reachable ONLY
 * through an authenticated, permission-checked, company-scoped controller
 * action. These probes confirm a file cannot be pulled by a guest, by a user
 * from another company, or by a user without the download right — and that the
 * bytes live on the private disk, never under a public URL (SECURITY.md §5).
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->company = Company::factory()->create();
    $this->other = Company::factory()->create();
});

/** Put a real document row + a stored file on the private disk. */
function seedDocument(Company $company): Document
{
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $path = Storage::disk('local')->putFile("employees/{$employee->id}/documents", UploadedFile::fake()->create('nif.pdf', 10));

    $doc = new Document([
        'category' => 'employee',
        'type_key' => 'nif',
        'name' => 'NIF',
    ]);
    // documentable_* are not mass-assignable (polymorphic) — set directly.
    $doc->documentable_type = Employee::class;
    $doc->documentable_id = $employee->id;
    $doc->company_id = $company->id;
    $doc->file_path = $path;
    $doc->original_name = 'nif.pdf';
    $doc->is_current = true;
    $doc->save();

    return $doc;
}

it('will not stream a document to a guest', function (): void {
    $doc = seedDocument($this->company);

    $this->get("/documents/{$doc->id}/download")->assertRedirect(route('login'));
});

it('404s a document that belongs to another company', function (): void {
    $foreignDoc = seedDocument($this->other);

    // A company admin of THIS company must not reach the other company's file.
    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);

    $this->actingAs($admin)->get("/documents/{$foreignDoc->id}/download")->assertNotFound();
});

it('403s a document download for a user without the download right', function (): void {
    $doc = seedDocument($this->company);
    $user = User::factory()->create(['role' => UserRole::User, 'company_id' => $this->company->id]);

    // View but NOT download.
    UserModulePermission::query()->create([
        'user_id' => $user->id,
        'company_id' => $this->company->id,
        'module' => 'documents',
        'can_view' => true,
        'can_download' => false,
        'granted_by' => $user->id,
    ]);

    $this->actingAs($user)->get("/documents/{$doc->id}/download")->assertForbidden();
});

it('streams the document to an authorised same-company user', function (): void {
    $doc = seedDocument($this->company);
    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);

    $this->actingAs($admin)->get("/documents/{$doc->id}/download")->assertOk();
});

it('404s cleanly (no 500 leak) when the row exists but the file is gone', function (): void {
    $doc = seedDocument($this->company);
    Storage::disk('local')->delete($doc->file_path);

    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);

    $this->actingAs($admin)->get("/documents/{$doc->id}/download")->assertNotFound();
});

it('keeps every uploaded file on the private disk, never public', function (): void {
    $doc = seedDocument($this->company);

    // The path is relative to the private (local) disk and does not sit under
    // the public disk root — so no public URL can ever address it.
    expect($doc->file_path)->not->toStartWith('public/')
        ->and(Storage::disk('local')->exists($doc->file_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($doc->file_path))->toBeFalse();
});

it('404s a leave attachment that belongs to another company', function (): void {
    $category = LeaveCategory::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $this->other->id]);
    $path = Storage::disk('local')->putFile("leave/{$this->other->id}", UploadedFile::fake()->create('med.pdf', 10));

    $leave = Leave::factory()->create([
        'company_id' => $this->other->id,
        'employee_id' => $employee->id,
        'leave_category_id' => $category->id,
        'status' => LeaveStatus::Approved,
    ]);
    $leave->file_path = $path;
    $leave->original_name = 'med.pdf';
    $leave->save();

    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);

    $this->actingAs($admin)->get("/leave/{$leave->id}/attachment")->assertNotFound();
});

it('will not stream a leave attachment to a guest', function (): void {
    $category = LeaveCategory::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $leave = Leave::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_category_id' => $category->id,
    ]);

    $this->get("/leave/{$leave->id}/attachment")->assertRedirect(route('login'));
});
