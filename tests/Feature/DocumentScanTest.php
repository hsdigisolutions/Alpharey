<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentAlertNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->company = Company::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

function scanDoc(Employee $employee, array $attrs): Document
{
    $document = new Document(array_merge([
        'category' => 'personal',
        'type_key' => 'nie_fotocopia',
    ], $attrs));
    $document->documentable()->associate($employee);
    $document->company_id = $employee->company_id;
    $document->setAttribute('file_path', 'x/y.pdf');
    $document->save();

    return $document;
}

it('alerts company admins at exactly 30 days before expiry', function (): void {
    scanDoc($this->employee, ['expiry_date' => now()->addDays(30)->toDateString()]);

    $this->artisan('verto:scan-documents')->assertSuccessful();

    Notification::assertSentTo($this->companyAdmin, DocumentAlertNotification::class);
});

it('does not alert on a non-milestone day', function (): void {
    scanDoc($this->employee, ['expiry_date' => now()->addDays(45)->toDateString()]);

    $this->artisan('verto:scan-documents');

    Notification::assertNothingSent();
});

it('sends a critical alert on the expiry day itself', function (): void {
    scanDoc($this->employee, ['expiry_date' => now()->toDateString()]);

    $this->artisan('verto:scan-documents');

    Notification::assertSentTo($this->companyAdmin, function (DocumentAlertNotification $notification): bool {
        return $notification->toDatabase($this->companyAdmin)['kind'] === 'expired';
    });
});

it('never alerts on an exempt document', function (): void {
    scanDoc($this->employee, ['expiry_date' => now()->addDays(30)->toDateString(), 'is_exempt' => true]);

    $this->artisan('verto:scan-documents');

    Notification::assertNothingSent();
});
