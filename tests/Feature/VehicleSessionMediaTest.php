<?php

use App\Enums\WageType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMileageHistory;
use App\Models\VehicleSession;
use App\Services\Workers\VehicleSessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Vehicle session media (photo + voice) + auto-mileage on return.
 * Step 2 exercises the service directly; the HTTP layer is covered separately.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->company = Company::factory()->create();
    $this->user = User::factory()->worker()->for($this->company)->create();
    $this->employee = Employee::factory()->for($this->company)->create([
        'user_id' => $this->user->id, 'wage_type' => WageType::Daily, 'can_use_vehicles' => true,
    ]);
    $this->vehicle = Vehicle::factory()->for($this->company)->create([
        'active' => true, 'is_available' => true, 'current_mileage' => 1201,
    ]);
    $this->service = app(VehicleSessionService::class);
});

it('stores the take photo + voice note on the private disk', function (): void {
    $session = $this->service->take(
        $this->vehicle, $this->employee, 1201, null,
        UploadedFile::fake()->image('take.jpg'),
        UploadedFile::fake()->create('take.webm', 80, 'audio/webm'),
        32,
    );

    expect($session->take_photo_path)->not->toBeNull()
        ->and($session->take_voice_note_path)->not->toBeNull()
        ->and($session->take_voice_duration)->toBe(32);
    Storage::disk('local')->assertExists($session->take_photo_path);
    Storage::disk('local')->assertExists($session->take_voice_note_path);
});

it('stores the return photo + voice and auto-creates the odometer row', function (): void {
    $session = $this->service->take($this->vehicle, $this->employee, 1201, null);

    $this->service->returnVehicle(
        $session, 1350, null, 'thanks',
        UploadedFile::fake()->image('return.jpg'),
        UploadedFile::fake()->create('return.webm', 90, 'audio/webm'),
        65,
    );

    $session->refresh();
    expect($session->return_photo_path)->not->toBeNull()
        ->and($session->return_voice_note_path)->not->toBeNull()
        ->and($session->return_voice_duration)->toBe(65)
        ->and($session->km_driven)->toBe(149);
    Storage::disk('local')->assertExists($session->return_photo_path);

    $row = VehicleMileageHistory::where('session_id', $session->id)->firstOrFail();
    expect($row->mileage_value)->toBe(1350)
        ->and($row->km_driven)->toBe(149)
        ->and($row->source)->toBe('worker_session')
        ->and($row->vehicle_id)->toBe($this->vehicle->id);
});

it('auto-creates the odometer row even with no photo (service allows it)', function (): void {
    $session = $this->service->take($this->vehicle, $this->employee, 1201, null);
    $this->service->returnVehicle($session, 1300, null, null);

    expect(VehicleMileageHistory::where('session_id', $session->id)->where('source', 'worker_session')->exists())->toBeTrue()
        ->and($session->fresh()->return_photo_path)->toBeNull();
});

it('does not set a voice duration when no voice note was recorded', function (): void {
    $session = $this->service->take(
        $this->vehicle, $this->employee, 1201, null,
        UploadedFile::fake()->image('take.jpg'), null, 99,
    );

    expect($session->take_photo_path)->not->toBeNull()
        ->and($session->take_voice_note_path)->toBeNull()
        ->and($session->take_voice_duration)->toBeNull();
});

// ── HTTP layer + admin downloads (Step 3) ──────────────────────────────────

it('requires a condition photo on take via HTTP', function (): void {
    $this->actingAs($this->user)
        ->post("/worker/vehicles/{$this->vehicle->id}/take", ['starting_mileage' => 1201])
        ->assertSessionHasErrors('photo');

    expect(VehicleSession::withoutGlobalScopes()->count())->toBe(0);
});

it('requires a condition photo on return via HTTP', function (): void {
    $session = $this->service->take($this->vehicle, $this->employee, 1201, null);

    $this->actingAs($this->user)
        ->post("/worker/vehicle-sessions/{$session->id}/return", ['ending_mileage' => 1300])
        ->assertSessionHasErrors('photo');
});

it('serves the session photo to an admin and audits it', function (): void {
    $session = $this->service->take(
        $this->vehicle, $this->employee, 1201, null, UploadedFile::fake()->image('take.jpg'), null, null,
    );
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($admin)
        ->get("/vehicles/{$this->vehicle->id}/sessions/{$session->id}/photo/take")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['action' => 'viewed']);
});

it('404s a session photo for an admin of another company', function (): void {
    $session = $this->service->take(
        $this->vehicle, $this->employee, 1201, null, UploadedFile::fake()->image('take.jpg'), null, null,
    );
    $otherAdmin = User::factory()->companyAdmin()->forCompany(Company::factory()->create())->create();

    $this->actingAs($otherAdmin)
        ->get("/vehicles/{$this->vehicle->id}/sessions/{$session->id}/photo/take")
        ->assertNotFound();
});

it('404s when the requested session media does not exist', function (): void {
    $session = $this->service->take($this->vehicle, $this->employee, 1201, null); // no photo
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($admin)
        ->get("/vehicles/{$this->vehicle->id}/sessions/{$session->id}/photo/take")
        ->assertNotFound();
});

it('ships enriched session data (media urls, distance, fuel, fines) to the admin detail page', function (): void {
    $session = $this->service->take($this->vehicle, $this->employee, 1201, null, UploadedFile::fake()->image('t.jpg'), null, null);
    $this->service->returnVehicle($session, 1350, null, 'ok', UploadedFile::fake()->image('r.jpg'), null, null);
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($admin)->get("/vehicles/{$this->vehicle->id}")
        ->assertInertia(fn ($page) => $page
            ->where('sessions.0.km_driven', 149)
            ->where('sessions.0.take_photo_url', fn ($u) => is_string($u) && str_contains($u, '/photo/take'))
            ->where('sessions.0.return_photo_url', fn ($u) => is_string($u) && str_contains($u, '/photo/return'))
            ->has('sessions.0.fuel')
            ->has('sessions.0.fines'));
});
