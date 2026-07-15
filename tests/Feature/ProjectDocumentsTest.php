<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->project = Project::factory()->forCompany($this->companyA)->create();
});

it('uploads a project document via the shared documents engine', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'project',
        'entity_id' => $this->project->id,
        'type_key' => 'permit',
        'category' => 'project',
        'file' => UploadedFile::fake()->create('licencia.pdf', 100),
        'expiry_date' => now()->addYear()->toDateString(),
    ])->assertRedirect();

    $document = Document::query()->where('type_key', 'permit')->firstOrFail();

    expect($document->getAttribute('file_path'))->toStartWith('projects/'.$this->project->id.'/documents/')
        ->and($document->company_id)->toBe($this->companyA->id);
});

it('rejects an unknown project document type', function (): void {
    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'project',
        'entity_id' => $this->project->id,
        'type_key' => 'not_a_project_type',
        'category' => 'project',
        'has_flag' => true,
    ])->assertStatus(422);
});

it('cannot attach a document to another company project', function (): void {
    $foreign = Project::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->admin)->post('/documents', [
        'entity_type' => 'project',
        'entity_id' => $foreign->id,
        'type_key' => 'permit',
        'category' => 'project',
        'has_flag' => true,
    ])->assertNotFound();
});
