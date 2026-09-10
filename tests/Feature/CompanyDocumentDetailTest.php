<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

beforeEach(function (): void {
    Storage::fake('local');
    $this->sa = User::factory()->superAdmin()->create();
});

/**
 * Upload a company document through the real endpoint.
 *
 * @param  array<string, mixed>  $payload
 */
function uploadCompanyDoc(Company $company, string $typeKey, array $payload = []): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test->post('/documents', array_merge([
        'entity_type' => 'company',
        'entity_id' => $company->id,
        'category' => 'company',
        'type_key' => $typeKey,
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ], $payload));
}

it('saves per-type metadata and contact fields, dropping empty and CCC keys', function (): void {
    $company = Company::factory()->create(['ccc' => '28/12345678/90']);

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', [
        'issue_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'metadata' => [
            'policy_number' => 'POL-1',
            'insurer' => 'Mapfre',
            'policy_type' => 'anual_abierta',
            'coverage_amount' => '300000',
            'premium' => '1200',
            'ccc' => 'should-be-ignored', // read-only from company, never stored
        ],
        'contacts' => [
            ['name' => 'Juan Gestor', 'role' => 'Gestor', 'phone' => '600111222', 'email' => 'juan@mapfre.es', 'notes' => ''],
            ['name' => 'Siniestros 24h', 'phone' => '112'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->where('company_id', $company->id)->where('type_key', 'poliza_rc')->firstOrFail();

    expect($doc->metadata['policy_number'])->toBe('POL-1')
        ->and($doc->metadata['coverage_amount'])->toBe('300000')
        ->and($doc->metadata)->not->toHaveKey('ccc')
        ->and($doc->contacts)->toHaveCount(2)
        ->and($doc->contacts[0]['name'])->toBe('Juan Gestor')
        ->and($doc->contacts[0]['role'])->toBe('Gestor')
        ->and($doc->contacts[1]['phone'])->toBe('112')
        ->and($doc->issue_date?->toDateString())->toBe('2026-01-01')
        ->and($doc->expiry_date?->toDateString())->toBe('2026-12-31');
});

it('accepts contacts on any type and drops fully-empty contact rows', function (): void {
    $company = Company::factory()->create();

    // recibo_rc had no contact section before — it does now (every type does).
    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'recibo_rc', [
        'contacts' => [
            ['name' => 'Contabilidad', 'phone' => '900100200'],
            ['name' => '', 'role' => '', 'phone' => '', 'email' => '', 'notes' => ''], // blank → dropped
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'recibo_rc')->firstOrFail();

    expect($doc->contacts)->toHaveCount(1)
        ->and($doc->contacts[0]['name'])->toBe('Contabilidad');
});

it('stores checkbox_group disciplines as an array', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'certificado_spa', [
        'metadata' => ['spa_company' => 'PrevCo', 'disciplines' => ['seguridad', 'vigilancia']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'certificado_spa')->firstOrFail();

    expect($doc->metadata['disciplines'])->toBe(['seguridad', 'vigilancia']);
});

it('rejects unknown metadata fields (whitelist only)', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', [
        'metadata' => ['policy_number' => 'X', 'bogus_field' => 'nope'],
    ])->assertSessionHasErrors('metadata.bogus_field');
});

it('rejects an invalid select option', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', [
        'metadata' => ['policy_type' => 'not_a_real_option'],
    ])->assertSessionHasErrors('metadata.policy_type');
});

it('rejects an invalid contact email', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'rea', [
        'contacts' => [['name' => 'X', 'email' => 'not-an-email']],
    ])->assertSessionHasErrors('contacts.0.email');
});

it('keeps previous versions in the history array, newest first', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', ['metadata' => ['policy_number' => 'V1']])
        ->assertSessionHasNoErrors();
    uploadCompanyDoc($company, 'poliza_rc', ['metadata' => ['policy_number' => 'V2']])
        ->assertSessionHasNoErrors();

    // The single company sits at index 0; its one current doc is v2 with v1 in history.
    $this->get('/companies')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Companies/Index')
        ->where('companies.0.documents.0.version', 2)
        ->where('companies.0.documents.0.metadata.policy_number', 'V2')
        ->has('companies.0.documents.0.history', 1)
        ->where('companies.0.documents.0.history.0.version', 1));

    expect(Document::query()->where('type_key', 'poliza_rc')->count())->toBe(2);
});

it('captures metadata, dates and contacts on the very first upload', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', [
        'issue_date' => '2026-02-01',
        'expiry_date' => '2027-01-31',
        'metadata' => ['policy_number' => 'FU-1', 'insurer' => 'AXA'],
        'contacts' => [['name' => 'Ana', 'role' => 'Gestora']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();

    expect($doc->metadata['policy_number'])->toBe('FU-1')
        ->and($doc->issue_date?->toDateString())->toBe('2026-02-01')
        ->and($doc->expiry_date?->toDateString())->toBe('2027-01-31')
        ->and($doc->contacts[0]['name'])->toBe('Ana');
});

it('downloads a previous version by its own id', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'rea', ['file' => UploadedFile::fake()->create('v1.pdf', 20, 'application/pdf')])
        ->assertSessionHasNoErrors();
    $v1 = Document::query()->where('type_key', 'rea')->firstOrFail();

    uploadCompanyDoc($company, 'rea', ['file' => UploadedFile::fake()->create('v2.pdf', 20, 'application/pdf')])
        ->assertSessionHasNoErrors();

    // v1 is now history (is_current false) but still downloadable by id.
    expect($v1->refresh()->is_current)->toBeFalse();
    $this->get("/documents/{$v1->id}/download")->assertOk();
});

it('injects the company CCC read-only into a document that declares a ccc field', function (): void {
    $company = Company::factory()->create(['ccc' => '28/99999999/11']);

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'documento_mutua', ['metadata' => ['mutua_name' => 'Fremap']])
        ->assertSessionHasNoErrors();

    $this->get('/companies')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('companies.0.documents.0.type_key', 'documento_mutua')
        ->where('companies.0.documents.0.ccc', '28/99999999/11'));
});

it('replaces the file in place keeping the same version and deleting the old file', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'rea', [
        'file' => UploadedFile::fake()->create('old.pdf', 50, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'rea')->firstOrFail();
    $oldPath = $doc->getAttribute('file_path');
    Storage::disk('local')->assertExists($oldPath);

    $this->post("/documents/{$doc->id}/replace", [
        'file' => UploadedFile::fake()->create('new.pdf', 60, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc->refresh();
    $newPath = $doc->getAttribute('file_path');

    expect($doc->version)->toBe(1)
        ->and($doc->original_name)->toBe('new.pdf')
        ->and($newPath)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($newPath);
});

it('edits metadata without changing the version or file, and leaves dates untouched when omitted', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', [
        'issue_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'metadata' => ['policy_number' => 'OLD'],
    ])->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();
    $filePath = $doc->getAttribute('file_path');

    $this->patch("/documents/{$doc->id}/metadata", [
        'metadata' => ['policy_number' => 'NEW', 'insurer' => 'AXA'],
        'contacts' => [['name' => 'Nueva Gestora', 'role' => 'Gestora']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc->refresh();

    expect($doc->version)->toBe(1)
        ->and($doc->getAttribute('file_path'))->toBe($filePath)
        ->and($doc->issue_date?->toDateString())->toBe('2026-01-01')
        ->and($doc->expiry_date?->toDateString())->toBe('2026-12-31')
        ->and($doc->metadata['policy_number'])->toBe('NEW')
        ->and($doc->metadata['insurer'])->toBe('AXA')
        ->and($doc->contacts[0]['name'])->toBe('Nueva Gestora');
});

it('fills metadata on a document that was uploaded with none', function (): void {
    // Regression: a doc created empty (metadata null) must accept a later edit —
    // the panel coerces the empty [] payload to an object so keys serialize.
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc')->assertSessionHasNoErrors();
    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();
    expect($doc->metadata)->toBeNull();

    $this->patch("/documents/{$doc->id}/metadata", [
        'metadata' => ['policy_number' => 'FIRST', 'insurer' => 'Generali'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($doc->refresh()->metadata['policy_number'])->toBe('FIRST');
});

it('rejects unknown metadata fields on the edit endpoint too', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc')->assertSessionHasNoErrors();
    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();

    $this->patch("/documents/{$doc->id}/metadata", [
        'metadata' => ['bogus_field' => 'x'],
    ])->assertSessionHasErrors('metadata.bogus_field');
});

it('stops a company admin from editing another company document (404)', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($companyA, 'poliza_rc')->assertSessionHasNoErrors();
    $docA = Document::query()->where('company_id', $companyA->id)->firstOrFail();

    $adminB = User::factory()->companyAdmin()->forCompany($companyB)->create();

    $this->actingAs($adminB)
        ->patch("/documents/{$docA->id}/metadata", ['metadata' => ['policy_number' => 'hack']])
        ->assertNotFound();

    $this->actingAs($adminB)
        ->post("/documents/{$docA->id}/replace", ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertNotFound();
});

it('corrects the issue and expiry dates in place via the metadata endpoint (client 2026-09)', function (): void {
    $company = Company::factory()->create();

    $this->actingAs($this->sa);
    uploadCompanyDoc($company, 'poliza_rc', [
        'issue_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();

    // A mistyped date is corrected without a re-upload — same version, same file.
    $filePath = $doc->getAttribute('file_path');
    $this->patch("/documents/{$doc->id}/metadata", [
        'issue_date' => '2026-02-15',
        'expiry_date' => '2027-02-14',
        'metadata' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc->refresh();
    expect($doc->version)->toBe(1)
        ->and($doc->getAttribute('file_path'))->toBe($filePath)
        ->and($doc->issue_date?->toDateString())->toBe('2026-02-15')
        ->and($doc->expiry_date?->toDateString())->toBe('2027-02-14');
});

it('previews an image inline, a PDF inline, and 404s a non-previewable type', function (): void {
    $company = Company::factory()->create();
    $this->actingAs($this->sa);

    // One real uploaded file; the mime column drives the preview decision, so we
    // set it deterministically per case (fake-upload mime detection is flaky).
    uploadCompanyDoc($company, 'poliza_rc')->assertSessionHasNoErrors();
    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();

    // PDF → inline.
    $doc->update(['mime' => 'application/pdf']);
    $pdfRes = $this->get("/documents/{$doc->id}/preview")->assertOk();
    expect($pdfRes->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdfRes->headers->get('content-disposition'))->toContain('inline');

    // Image → inline.
    $doc->update(['mime' => 'image/jpeg']);
    $imgRes = $this->get("/documents/{$doc->id}/preview")->assertOk();
    expect($imgRes->headers->get('content-type'))->toContain('image/')
        ->and($imgRes->headers->get('content-disposition'))->toContain('inline');

    // A non-previewable type (a Word doc) is download-only → 404 on preview.
    $doc->update(['mime' => 'application/msword']);
    $this->get("/documents/{$doc->id}/preview")->assertNotFound();

    // The inline view is audited like a download.
    $doc->update(['mime' => 'application/pdf']);
    $this->get("/documents/{$doc->id}/preview")->assertOk();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'viewed', 'model_type' => $doc->getMorphClass(), 'model_id' => (string) $doc->id,
    ]);
});

it('refuses a preview across companies (tenancy)', function (): void {
    $mine = Company::factory()->create();
    $this->actingAs($this->sa);
    uploadCompanyDoc($mine, 'poliza_rc')->assertSessionHasNoErrors();
    $doc = Document::query()->where('type_key', 'poliza_rc')->firstOrFail();
    $doc->update(['mime' => 'application/pdf']);

    // A company admin of ANOTHER company cannot preview it.
    $other = Company::factory()->create();
    $admin = User::factory()->companyAdmin()->forCompany($other)->create();
    $this->actingAs($admin)->get("/documents/{$doc->id}/preview")->assertNotFound();
});
