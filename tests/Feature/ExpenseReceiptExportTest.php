<?php

use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Expense receipt documents view + the two tax-filing exports (ZIP of originals
 * with an Excel/PDF index, and a combined review PDF). The receipt set is every
 * expense carrying a stored file, filtered by date + scope.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

/** Attach a real fake file to an expense on the private disk. */
function receiptExpense(Company $company, array $attrs = [], string $file = 'factura.png'): Expense
{
    $expense = Expense::factory()->create(array_merge([
        'company_id' => $company->id,
        'date' => '2026-06-15',
        'subtotal' => '100', 'vat_amount' => '21', 'total' => '121',
    ], $attrs));

    $stored = UploadedFile::fake()->image($file)->store("expenses/{$company->id}", 'local');
    $expense->forceFill(['file_path' => $stored, 'original_name' => $file])->save();

    return $expense;
}

it('lists expenses with receipts in the receipts payload', function (): void {
    $vendor = Vendor::factory()->create(['name' => 'Ferretería López']);
    receiptExpense($this->company, ['vendor_id' => $vendor->id, 'number' => 'F-2026-001']);
    Expense::factory()->create(['company_id' => $this->company->id]); // no file → excluded

    $this->actingAs($this->admin)->get('/expenses')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('receipts', 1)
            ->where('receipts.0.vendor', 'Ferretería López')
            ->where('receipts.0.total', 121)
            ->where('receipts.0.is_image', true)
            // YYYY-MM-DD__vendor__€total__facturaNo.ext (vendor ascii-slugged).
            ->where('receipts.0.filename', '2026-06-15__Ferreteria-Lopez__€121.00__F-2026-001.png'));
});

it('filters receipts by a custom date range', function (): void {
    receiptExpense($this->company, ['date' => '2026-05-10']);
    receiptExpense($this->company, ['date' => '2026-06-15']);

    $this->actingAs($this->admin)->get('/expenses?rc_from=2026-06-01&rc_to=2026-06-30')
        ->assertInertia(fn (Assert $p) => $p->has('receipts', 1)
            ->where('receipts.0.date', '2026-06-15'));
});

it('downloads a ZIP of receipts and audits it', function (): void {
    receiptExpense($this->company);

    $res = $this->actingAs($this->admin)->get('/expenses/receipts/export/zip');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('zip');

    $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'module' => 'expenses']);
});

it('downloads a combined PDF of receipts', function (): void {
    receiptExpense($this->company);

    $res = $this->actingAs($this->admin)->get('/expenses/receipts/export/pdf');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('pdf');
});

it('handles the zero-receipts case gracefully (no broken file)', function (): void {
    // A period with no receipts → a friendly redirect, not an empty download.
    $this->actingAs($this->admin)->get('/expenses/receipts/export/zip?rc_from=2000-01-01&rc_to=2000-01-31')
        ->assertRedirect();
    $this->actingAs($this->admin)->get('/expenses/receipts/export/pdf?from=2000-01-01&to=2000-01-31')
        ->assertRedirect();
});

it('previews a receipt inline (not as an attachment)', function (): void {
    $expense = receiptExpense($this->company);

    $res = $this->actingAs($this->admin)->get("/expenses/{$expense->id}/receipt/preview");
    $res->assertOk();
    // Inline, so no attachment disposition.
    expect((string) $res->headers->get('content-disposition'))->not->toContain('attachment');

    $this->assertDatabaseHas('audit_logs', ['action' => 'viewed', 'module' => 'expenses']);
});

it('cannot preview another company receipt (404)', function (): void {
    $foreign = receiptExpense(Company::factory()->create());

    $this->actingAs($this->admin)->get("/expenses/{$foreign->id}/receipt/preview")
        ->assertNotFound();
});

it('a Super Admin can scope receipts to all companies; a company admin cannot', function (): void {
    $other = Company::factory()->create();
    receiptExpense($this->company);
    receiptExpense($other);

    $sa = User::factory()->create(['role' => 'super_admin']);
    // SA + scope=all → both companies' receipts.
    $this->actingAs($sa)->get('/expenses?rc_scope=all')
        ->assertInertia(fn (Assert $p) => $p->has('receipts', 2)->where('canScopeAll', true));

    // A company admin asking for scope=all is silently kept to their own company.
    $this->actingAs($this->admin)->get('/expenses?rc_scope=all')
        ->assertInertia(fn (Assert $p) => $p->has('receipts', 1)->where('canScopeAll', false));
});

it('refuses the receipt exports without the export permission', function (): void {
    receiptExpense($this->company);
    $viewer = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $viewer->id, 'company_id' => $this->company->id,
        'module' => 'expenses', 'action' => 'view', 'allowed' => true,
    ]);

    $this->actingAs($viewer)->get('/expenses/receipts/export/zip')->assertForbidden();
    $this->actingAs($viewer)->get('/expenses/receipts/export/pdf')->assertForbidden();
});
