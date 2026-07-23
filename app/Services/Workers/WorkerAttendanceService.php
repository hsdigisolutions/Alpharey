<?php

namespace App\Services\Workers;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Support\PeriodLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A worker's own check-in / check-out / absence, from the phone.
 *
 * The wage side is NOT reimplemented here — it delegates to AttendanceService,
 * which freezes the wage snapshot and computes the day total from the same
 * clock arithmetic the clerk grid uses. A worker punch is just an ordinary
 * attendance row that also carries where and when it was made, and a selfie.
 * That keeps payroll blind to how the row was created: a phone punch and a
 * hand-typed timesheet flow through the identical wage path.
 *
 * The location + photo columns are set AFTER the wage row is written and are
 * not mass assignable, so nothing a phone POSTs can reach a wage field.
 *
 * GPS is evidence, not proof: a refused permission does not block the punch
 * (client decision), it records `location_denied`, and an Android device can
 * fake coordinates — so these values are for an admin to read, never a gate.
 */
class WorkerAttendanceService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly PeriodLock $lock,
    ) {}

    /**
     * Start the day. One check-in per employee per date — the unique index
     * enforces it, and this refuses early with a friendly message.
     *
     * @param  array{lat: float|null, lng: float|null, accuracy: float|null, denied: bool}  $location
     */
    public function checkIn(Employee $employee, array $location, ?UploadedFile $photo): Attendance
    {
        $today = now()->toDateString();
        $existing = $this->todayFor($employee, $today);

        if ($existing !== null && $existing->check_in !== null) {
            throw ValidationException::withMessages([
                'check_in' => __('ui.worker.already_checked_in'),
            ]);
        }

        // Reject a punch into a month payroll has closed (system-wide lock).
        $this->lock->assertOpen($employee->company_id, $today, 'check_in');

        // Store the selfie first, on the private disk, randomized name — the
        // exact handling as the documents engine. A stray file on a failed row
        // is harmless; a row referencing a missing file is not.
        $photoPath = $this->storePhoto($employee, $photo);

        return DB::transaction(function () use ($employee, $today, $location, $photoPath): Attendance {
            // AttendanceService freezes the wage snapshot and computes the day.
            // company_id comes from the worker's own company context inside it.
            $attendance = $this->attendance->create([
                'employee_id' => $employee->id,
                'date' => $today,
                'mode' => AttendanceMode::Hourly->value,
                'check_in' => now()->format('H:i'),
                'status' => AttendanceStatus::Present->value,
            ]);

            $attendance->check_in_at = now();
            $this->applyLocation($attendance, 'check_in', $location);
            $attendance->check_in_photo_path = $photoPath;
            $attendance->source = 'worker';
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * End the day. Requires an open check-in with no check-out yet; the hours
     * and total are recomputed from the two clock times by AttendanceService.
     *
     * @param  array{lat: float|null, lng: float|null, accuracy: float|null, denied: bool}  $location
     */
    public function checkOut(Employee $employee, array $location): Attendance
    {
        $today = now()->toDateString();
        $attendance = $this->todayFor($employee, $today);

        if ($attendance === null || $attendance->check_in === null) {
            throw ValidationException::withMessages([
                'check_out' => __('ui.worker.not_checked_in'),
            ]);
        }

        if ($attendance->check_out !== null) {
            throw ValidationException::withMessages([
                'check_out' => __('ui.worker.already_checked_out'),
            ]);
        }

        $this->lock->assertOpen($employee->company_id, $today, 'check_out');

        return DB::transaction(function () use ($attendance, $location): Attendance {
            // update() recomputes hours_worked + total_amount from the snapshot.
            $attendance = $this->attendance->update($attendance, [
                'check_out' => now()->format('H:i'),
            ]);

            $attendance->check_out_at = now();
            $this->applyLocation($attendance, 'check_out', $location);
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * Report an absence with a reason. Only possible when there is no
     * attendance for today at all — a worker who already checked in was not
     * absent, and the unique index would reject a second row anyway.
     */
    public function reportAbsence(Employee $employee, string $note): Attendance
    {
        $today = now()->toDateString();

        if ($this->todayFor($employee, $today) !== null) {
            throw ValidationException::withMessages([
                'note' => __('ui.worker.already_has_record'),
            ]);
        }

        $this->lock->assertOpen($employee->company_id, $today, 'note');

        return DB::transaction(function () use ($employee, $today, $note): Attendance {
            // Absent = zero hours and zero pay; manual override so the wage
            // recompute leaves the total at 0 rather than deriving it.
            $attendance = $this->attendance->create([
                'employee_id' => $employee->id,
                'date' => $today,
                'mode' => AttendanceMode::ProjectBased->value,
                'status' => AttendanceStatus::Absent->value,
                'hours_worked' => '0',
                'total_amount' => '0',
                'manual_wage_override' => true,
            ]);

            $attendance->worker_note = $note;
            $attendance->source = 'worker';
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * Today's row for this employee, tenant scope dropped (a worker is one
     * employee, reached only through their own id) but SoftDeletes kept.
     */
    public function todayFor(Employee $employee, ?string $date = null): ?Attendance
    {
        return Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date ?? now()->toDateString())
            ->first();
    }

    /**
     * @param  array{lat: float|null, lng: float|null, accuracy: float|null, denied: bool}  $location
     */
    private function applyLocation(Attendance $attendance, string $prefix, array $location): void
    {
        if ($location['denied'] || $location['lat'] === null || $location['lng'] === null) {
            // No fix: flag it and leave the coordinates null. The punch stands.
            $attendance->location_denied = true;

            return;
        }

        // Decimal columns take strings (matches the numeric-string @property).
        $attendance->setAttribute("{$prefix}_lat", (string) $location['lat']);
        $attendance->setAttribute("{$prefix}_lng", (string) $location['lng']);

        if ($location['accuracy'] !== null) {
            $attendance->setAttribute("{$prefix}_accuracy", (string) $location['accuracy']);
        }
    }

    private function storePhoto(Employee $employee, ?UploadedFile $photo): ?string
    {
        if ($photo === null) {
            return null;
        }

        // Private disk, per-employee folder, randomized name — never public.
        $path = $photo->store("attendance-selfies/{$employee->company_id}/{$employee->id}", 'local');

        return $path === false ? null : $path;
    }
}
