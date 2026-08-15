<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentAlertNotification;
use Illuminate\Support\Carbon;
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
    // Pin to the 15th so the scan's first-of-month monthly sweep does not fire
    // and the 45-day expiry is not a 30/60/90-day milestone from this date.
    Carbon::setTestNow(now()->setDay(15));
    scanDoc($this->employee, ['expiry_date' => now()->addDays(45)->toDateString()]);

    $this->artisan('verto:scan-documents');

    Notification::assertNothingSent();
    Carbon::setTestNow();
});

it('sends a critical alert on the expiry day itself', function (): void {
    scanDoc($this->employee, ['expiry_date' => now()->toDateString()]);

    $this->artisan('verto:scan-documents');

    Notification::assertSentTo($this->companyAdmin, function (DocumentAlertNotification $notification): bool {
        return $notification->toDatabase($this->companyAdmin)['kind'] === 'expired';
    });
});

it('alerts once for a document that already expired on a missed day', function (): void {
    // Expired five days ago, never notified — the old today..today+90 bound
    // silently skipped this. It must now fire an expired alert.
    $document = scanDoc($this->employee, ['expiry_date' => now()->subDays(5)->toDateString()]);

    $this->artisan('verto:scan-documents');

    Notification::assertSentTo($this->companyAdmin, function (DocumentAlertNotification $notification): bool {
        return $notification->toDatabase($this->companyAdmin)['kind'] === 'expired';
    });

    expect($document->fresh()->expiry_notified_at)->not->toBeNull();
});

it('does not re-alert an already-notified expired document', function (): void {
    $document = scanDoc($this->employee, ['expiry_date' => now()->subDays(5)->toDateString()]);
    $document->forceFill(['expiry_notified_at' => now()->subDay()])->saveQuietly();

    $this->artisan('verto:scan-documents');

    Notification::assertNothingSent();
});

it('never alerts on an exempt document', function (): void {
    Carbon::setTestNow(now()->setDay(15));
    scanDoc($this->employee, ['expiry_date' => now()->addDays(30)->toDateString(), 'is_exempt' => true]);

    $this->artisan('verto:scan-documents');

    Notification::assertNothingSent();
    Carbon::setTestNow();
});
