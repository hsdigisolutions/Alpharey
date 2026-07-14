<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentStatus;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

function makeDoc(Employee $employee, array $attrs): Document
{
    $document = new Document(array_merge([
        'category' => 'personal',
        'type_key' => 'dni',
    ], $attrs));
    $document->documentable()->associate($employee);
    $document->company_id = $employee->company_id;
    $document->setAttribute('file_path', 'x/y.pdf');
    $document->save();

    return $document;
}

it('classifies an expired document as danger', function (): void {
    $document = makeDoc($this->employee, ['expiry_date' => now()->subDay()->toDateString()]);

    [$status] = app(DocumentStatus::class)->of($document);

    expect($status)->toBe('danger');
});

it('classifies a document expiring inside the warn window as warn', function (): void {
    $document = makeDoc($this->employee, ['expiry_date' => now()->addDays(20)->toDateString()]);

    [$status] = app(DocumentStatus::class)->of($document);

    expect($status)->toBe('warn');
});

it('classifies a far-future document as ok', function (): void {
    $document = makeDoc($this->employee, ['expiry_date' => now()->addYears(2)->toDateString()]);

    [$status] = app(DocumentStatus::class)->of($document);

    expect($status)->toBe('ok');
});

it('excludes exempt documents from scoring', function (): void {
    $document = makeDoc($this->employee, ['expiry_date' => now()->subDay()->toDateString(), 'is_exempt' => true]);

    expect(app(DocumentStatus::class)->score($document))->toBeNull();
});

it('renders the compliance center with a summary and rows', function (): void {
    makeDoc($this->employee, ['expiry_date' => now()->subDay()->toDateString()]);
    makeDoc($this->employee, ['type_key' => 'nie', 'expiry_date' => now()->addYears(2)->toDateString()]);

    $this->actingAs($this->admin)->get('/compliance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Compliance')
            ->where('summary.danger', 1)
            ->where('summary.ok', 1)
            ->has('rows', 2));
});

it('marks a document exempt via the compliance action', function (): void {
    $document = makeDoc($this->employee, ['expiry_date' => now()->subDay()->toDateString()]);

    $this->actingAs($this->admin)->post("/documents/{$document->id}/exempt")->assertRedirect();

    expect($document->fresh()->is_exempt)->toBeTrue();
});

it('scopes compliance rows to the admin company', function (): void {
    makeDoc($this->employee, ['expiry_date' => now()->addYear()->toDateString()]);

    $otherCompany = Company::factory()->create();
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create();
    makeDoc($foreignEmployee, ['expiry_date' => now()->addYear()->toDateString()]);

    $this->actingAs($this->admin)->get('/compliance')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
});
