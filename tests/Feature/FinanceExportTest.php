<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->client = Client::factory()->create();
    $this->month = '2026-05';
});

it('exports the payroll month as a spreadsheet', function (): void {
    Excel::fake();

    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);
    app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);

    $this->actingAs($this->admin)->get('/payroll/export?month='.$this->month)->assertOk();

    Excel::assertDownloaded('nominas-'.$this->month.'.xlsx');
});

it('refuses the payroll export to someone who may not see pay', function (): void {
    // export without payroll.view would leak every wage in the company
    $user = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'payroll', 'can_export' => true, 'can_view' => false,
    ]);

    $this->actingAs($user)->get('/payroll/export?month='.$this->month)->assertForbidden();
});

it('exports every payslip for the month as one PDF', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);
    app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);

    $this->actingAs($this->admin)->get('/payroll/payslips?month='.$this->month)
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('renders the payslip PDF with the company logo and address (Check 5)', function (): void {
    Storage::fake('local');
    $this->company->update([
        'address' => 'Calle Real 1, Málaga', 'cif' => 'B99999999',
        'logo_path' => UploadedFile::fake()->image('logo.png', 200, 80)->store('company-logos', 'local'),
    ]);
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);
    app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);
    $payroll = Payroll::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    // The blade renders the logo + address branch without error.
    $this->actingAs($this->admin)->get("/payroll/{$payroll->id}/payslip")
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('exports the invoices tab as a spreadsheet', function (): void {
    Excel::fake();

    Invoice::factory()->create([
        'company_id' => $this->company->id, 'client_id' => $this->client->id,
    ]);

    $this->actingAs($this->admin)->get('/invoices/export?tab=sale')->assertOk();

    Excel::assertDownloaded('facturas-sale.xlsx');
});

it('exports the CURRENT filtered view, not everything', function (): void {
    Excel::fake();

    Invoice::factory()->create([
        'company_id' => $this->company->id, 'client_id' => $this->client->id,
        'invoice_date' => '2026-05-10', 'payment_status' => 'unpaid',
    ]);
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'client_id' => $this->client->id,
        'invoice_date' => '2026-05-11', 'payment_status' => 'paid',
    ]);

    $this->actingAs($this->admin)->get('/invoices/export?tab=sale&payment_status=paid')->assertOk();

    // The export runs the screen's query — the filter must reach the sheet.
    Excel::assertDownloaded('facturas-sale.xlsx', function ($export) {
        return $export->collection()->count() === 1
            && $export->collection()->first()->payment_status->value === 'paid';
    });
});

it('does not let the export route be swallowed by the show route', function (): void {
    Excel::fake();

    // /invoices/export must not resolve as /invoices/{invoice}
    $this->actingAs($this->admin)->get('/invoices/export?tab=sale')->assertOk();

    Excel::assertDownloaded('facturas-sale.xlsx');
});

it('exports commissions as a spreadsheet', function (): void {
    Excel::fake();

    $this->actingAs($this->admin)->get('/commissions/export?month='.$this->month)->assertOk();

    Excel::assertDownloaded('comisiones-'.$this->month.'.xlsx');
});

it('denies the invoice export without the export permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'invoices', 'can_view' => true,
    ]);

    $this->actingAs($user)->get('/invoices/export?tab=sale')->assertForbidden();
});
