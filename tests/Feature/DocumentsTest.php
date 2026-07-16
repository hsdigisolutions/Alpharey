<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function grantDocs(User $user, Company $company, array $actions): void
{
    UserModulePermission::query()->create(array_merge([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'module' => 'documents',
    ], $actions));
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('uploads an employee document to private storage and audits it', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee',
        'entity_id' => $this->employee->id,
        'type_key' => 'nie_fotocopia',
        'category' => 'personal',
        'file' => UploadedFile::fake()->create('dni.pdf', 200, 'application/pdf'),
        'expiry_date' => now()->addYear()->toDateString(),
    ])->assertRedirect();

    $document = Document::query()->where('type_key', 'nie_fotocopia')->firstOrFail();

    expect($document->getAttribute('file_path'))->toStartWith('employees/'.$this->employee->id.'/documents/')
        ->and(Storage::disk('local')->exists($document->getAttribute('file_path')))->toBeTrue()
        ->and($document->original_name)->toBe('dni.pdf')
        ->and(AuditLog::query()->where('action', 'uploaded')->where('module', 'documents')->exists())->toBeTrue();
});

it('never stores uploads in a public location', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee',
        'entity_id' => $this->employee->id,
        'type_key' => 'nie_fotocopia',
        'category' => 'personal',
        'file' => UploadedFile::fake()->create('dni.pdf', 100),
    ]);

    $path = Document::query()->firstOrFail()->getAttribute('file_path');

    expect($path)->not->toContain('public');
});

it('creates a new version when re-uploading the same type', function (): void {
    $payload = [
        'entity_type' => 'employee',
        'entity_id' => $this->employee->id,
        'type_key' => 'documento_alta_ss',
        'category' => 'employment',
    ];

    $this->actingAs($this->admin)->post('/documents', $payload + ['file' => UploadedFile::fake()->create('c1.pdf', 50)]);
    $this->post('/documents', $payload + ['file' => UploadedFile::fake()->create('c2.pdf', 50)]);

    $current = Document::query()->where('type_key', 'documento_alta_ss')->where('is_current', true)->firstOrFail();

    expect($current->version)->toBe(2)
        ->and(Document::query()->where('type_key', 'documento_alta_ss')->count())->toBe(2)
        ->and(Document::query()->where('type_key', 'documento_alta_ss')->where('is_current', true)->count())->toBe(1);
});

it('downloads only through a permission-checked route and audits it', function (): void {
    $file = UploadedFile::fake()->create('dni.pdf', 100);
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee', 'entity_id' => $this->employee->id,
        'type_key' => 'nie_fotocopia', 'category' => 'personal', 'file' => $file,
    ]);
    $document = Document::query()->firstOrFail();

    $this->get("/documents/{$document->id}/download")->assertOk();

    expect(AuditLog::query()->where('action', 'downloaded')->exists())->toBeTrue();
});

it('denies download without documents.download permission', function (): void {
    $file = UploadedFile::fake()->create('dni.pdf', 100);
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee', 'entity_id' => $this->employee->id,
        'type_key' => 'nie_fotocopia', 'category' => 'personal', 'file' => $file,
    ]);
    $document = Document::query()->firstOrFail();

    $user = User::factory()->forCompany($this->company)->create();
    grantDocs($user, $this->company, ['can_view' => true]);

    $this->actingAs($user)->get("/documents/{$document->id}/download")->assertForbidden();
});

it('rejects an unknown document type', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee',
        'entity_id' => $this->employee->id,
        'type_key' => 'not_a_real_type',
        'category' => 'personal',
        'has_flag' => true,
    ])->assertStatus(422);
});

it('accepts a Yes/No flag without a file', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee',
        'entity_id' => $this->employee->id,
        'type_key' => 'documento_idc',
        'category' => 'employment',
        'has_flag' => true,
    ])->assertRedirect();

    expect(Document::query()->where('type_key', 'documento_idc')->value('has_flag'))->toBe(true);
});

it('soft deletes a document, preserving metadata', function (): void {
    $file = UploadedFile::fake()->create('dni.pdf', 100);
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee', 'entity_id' => $this->employee->id,
        'type_key' => 'nie_fotocopia', 'category' => 'personal', 'file' => $file,
    ]);
    $document = Document::query()->firstOrFail();

    $this->delete("/documents/{$document->id}")->assertRedirect();

    expect(Document::query()->find($document->id))->toBeNull()
        ->and(Document::withTrashed()->find($document->id))->not->toBeNull();
});

it('lets a company admin upload their own company document', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'company',
        'entity_id' => $this->company->id,
        'type_key' => 'poliza_rc',
        'category' => 'company',
        'has_flag' => true,
        'expiry_date' => now()->addYear()->toDateString(),
    ])->assertRedirect();

    expect(Document::query()->where('type_key', 'poliza_rc')->exists())->toBeTrue();
});

it('forbids a custom user with documents.upload from touching company documents', function (): void {
    // Even with the module permission, company documents require an admin role
    $user = User::factory()->forCompany($this->company)->create();
    grantDocs($user, $this->company, ['can_view' => true, 'can_upload' => true]);

    $this->actingAs($user)->post('/documents', [
        'entity_type' => 'company',
        'entity_id' => $this->company->id,
        'type_key' => 'poliza_rc',
        'category' => 'company',
        'has_flag' => true,
    ])->assertForbidden();
});

it('forbids a company admin from touching another company document', function (): void {
    $otherCompany = Company::factory()->create();

    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'company',
        'entity_id' => $otherCompany->id,
        'type_key' => 'poliza_rc',
        'category' => 'company',
        'has_flag' => true,
    ])->assertNotFound();
});

it('cannot upload a document to another company employee', function (): void {
    $otherCompany = Company::factory()->create();
    $foreign = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'employee',
        'entity_id' => $foreign->id,
        'type_key' => 'nie_fotocopia',
        'category' => 'personal',
        'has_flag' => true,
    ])->assertNotFound();
});
