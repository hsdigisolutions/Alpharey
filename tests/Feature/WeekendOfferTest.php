<?php

use App\Enums\WeekendRateType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WeekendWorkOffer;
use App\Services\Workers\WorkerAttendanceService;
use Illuminate\Validation\ValidationException;

/**
 * Weekend Work Offers. Saturday/Sunday are days off by default: a worker can
 * only punch in when an admin has published an offer for that date AND invited
 * them. 2026-08-08 is a Saturday; 2026-08-10 is a Monday.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '10',
    ]);
    $this->service = app(WorkerAttendanceService::class);
});

afterEach(fn () => $this->travelBack());

function makeOffer(Company $company, string $date, array $invited, string $rate = 'x2'): WeekendWorkOffer
{
    $offer = new WeekendWorkOffer([
        'offer_date' => $date,
        'weekend_rate_type' => $rate,
        'invited_employee_ids' => $invited,
    ]);
    $offer->company_id = $company->id;
    $offer->save();

    return $offer;
}

it('blocks a weekend check-in when there is no offer', function (): void {
    $this->travelTo('2026-08-08 09:00'); // Saturday

    expect(fn () => $this->service->checkIn($this->employee, ['lat' => null, 'lng' => null, 'accuracy' => null, 'denied' => true], null))
        ->toThrow(ValidationException::class);
});

it('allows a weekend check-in for an invited worker and applies the offer rate', function (): void {
    $this->travelTo('2026-08-08 09:00'); // Saturday
    makeOffer($this->company, '2026-08-08', [$this->employee->id], 'x2');

    $att = $this->service->checkIn($this->employee, ['lat' => null, 'lng' => null, 'accuracy' => null, 'denied' => true], null);

    expect($att->is_weekend)->toBeTrue()
        ->and($att->weekend_rate_type)->toBe(WeekendRateType::X2);
});

it('blocks a weekend check-in for a worker who was not invited', function (): void {
    $this->travelTo('2026-08-08 09:00'); // Saturday
    makeOffer($this->company, '2026-08-08', [999999], 'x2'); // someone else

    expect(fn () => $this->service->checkIn($this->employee, ['lat' => null, 'lng' => null, 'accuracy' => null, 'denied' => true], null))
        ->toThrow(ValidationException::class);
});

it('allows a normal weekday check-in with no offer', function (): void {
    $this->travelTo('2026-08-10 09:00'); // Monday

    $att = $this->service->checkIn($this->employee, ['lat' => null, 'lng' => null, 'accuracy' => null, 'denied' => true], null);

    expect($att->status->value)->toBe('present')
        ->and($att->is_weekend)->toBeFalse();
});

it('does not leak another company offer to a worker', function (): void {
    $this->travelTo('2026-08-08 09:00'); // Saturday
    $other = Company::factory()->create();
    // An offer under a DIFFERENT company inviting this employee id must not count.
    makeOffer($other, '2026-08-08', [$this->employee->id], 'x2');

    expect(fn () => $this->service->checkIn($this->employee, ['lat' => null, 'lng' => null, 'accuracy' => null, 'denied' => true], null))
        ->toThrow(ValidationException::class);
});

it('lets an admin publish a weekend offer', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($admin)->post('/weekend-offers', [
        'offer_date' => '2026-08-08',
        'weekend_rate_type' => 'x1.5',
        'invited_employee_ids' => [$this->employee->id],
    ])->assertRedirect();

    $offer = WeekendWorkOffer::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    expect($offer->offer_date->toDateString())->toBe('2026-08-08')
        ->and($offer->invites($this->employee->id))->toBeTrue();
});

it('refuses a weekend offer on a weekday date', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($admin)->post('/weekend-offers', [
        'offer_date' => '2026-08-10', // Monday
        'weekend_rate_type' => 'normal',
        'invited_employee_ids' => [$this->employee->id],
    ])->assertSessionHasErrors('offer_date');
});

it('cannot delete another company weekend offer', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $other = Company::factory()->create();
    $offer = makeOffer($other, '2026-08-08', [1], 'normal');

    $this->actingAs($admin)->delete("/weekend-offers/{$offer->id}")->assertNotFound();
});
