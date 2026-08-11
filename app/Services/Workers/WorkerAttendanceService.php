<?php

namespace App\Services\Workers;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\NotificationType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\WeekendWorkOffer;
use App\Services\Attendance\AttendanceService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\PeriodLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
        private readonly NotificationDispatcher $notifications,
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

        // Weekends are DAYS OFF by default: a check-in is only allowed when an
        // admin has published a weekend offer for today AND invited this worker.
        // The offer also carries the weekend rate applied below.
        $offer = null;
        if (Carbon::parse($today)->isWeekend()) {
            $offer = $this->weekendOfferFor($employee, $today);

            if ($offer === null) {
                throw ValidationException::withMessages([
                    'check_in' => __('ui.worker.rest_day'),
                ]);
            }
        }

        // Reject a punch into a month payroll has closed (system-wide lock).
        $this->lock->assertOpen($employee->company_id, $today, 'check_in');

        // Store the selfie first, on the private disk, randomized name — the
        // exact handling as the documents engine. A stray file on a failed row
        // is harmless; a row referencing a missing file is not.
        $photoPath = $this->storePhoto($employee, $photo);

        $attendance = DB::transaction(function () use ($employee, $today, $location, $photoPath, $offer): Attendance {
            // createForWorker() bypasses resolveEmployee() which requires a CRM
            // session (CurrentCompany) that workers never have. The employee is
            // already verified by WorkerController — pass it directly.
            $attendance = $this->attendance->createForWorker($employee, [
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

            // Weekend offer: carry its rate onto the row so the day is priced
            // with the premium (the weekend day was server-detected in recompute).
            if ($offer !== null) {
                $attendance->weekend_rate_type = $offer->weekend_rate_type;
                $attendance->weekend_rate_amount = $offer->weekend_rate_amount;
                $this->attendance->recalculateRow($attendance, $employee);
            }

            return $attendance;
        });

        // GPS is evidence, not a gate: the punch stood, but a check-in with no
        // location leaves the admin blind to where the worker was — so notify
        // the company's admins (delivery governed by the Settings matrix).
        if ($attendance->location_denied) {
            $this->notifyGpsMissing($employee);
        }

        return $attendance;
    }

    /**
     * Alert the company's admins that a worker checked in without a GPS fix.
     * Routed through the dispatcher so the notification matrix (Screen 26)
     * controls who receives it — a sender never picks recipients by hand.
     */
    private function notifyGpsMissing(Employee $employee): void
    {
        $this->notifications->dispatch(NotificationType::WorkerGpsMissing, $employee->company_id, [
            'title_es' => "{$employee->full_name} fichó sin ubicación GPS",
            'title_en' => "{$employee->full_name} checked in without GPS location",
            'entity' => $employee->full_name,
            'company' => $employee->company?->name,
            'url' => null,
        ]);
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

        $attendance = DB::transaction(function () use ($employee, $attendance, $location): Attendance {
            // update() recomputes hours_worked + total_amount from the snapshot.
            $attendance = $this->attendance->update($attendance, [
                'check_out' => now()->format('H:i'),
            ]);

            // Grade the day AUTOMATICALLY from the hours just computed (full /
            // half / hourly per the company thresholds) and re-price by it. The
            // worker never picks a type; an admin can override later.
            $this->attendance->applyAutoDayType($attendance, $employee);

            $attendance->check_out_at = now();
            $this->applyLocation($attendance, 'check_out', $location);
            $this->applyMismatch($attendance, $location);
            $attendance->save();

            return $attendance;
        });

        $this->notifyIfShortShift($employee, $attendance);

        return $attendance;
    }

    /**
     * A day that fell SHORT of the company's half-day threshold (worked, but
     * under half a jornada) pings the Company Admins + Managers so they can
     * check whether the short shift is correct. Fires only for a real, >0-hour
     * day — a 0-hour open check-in is not a short shift.
     */
    private function notifyIfShortShift(Employee $employee, Attendance $attendance): void
    {
        $hours = (float) $attendance->hours_worked;
        $half = $this->attendance->dayTypeThresholds($employee->company_id)['half'];

        if ($hours <= 0 || $hours >= $half) {
            return;
        }

        $hoursLabel = $this->hoursLabel($hours);

        $this->notifications->dispatch(NotificationType::ShortHours, $employee->company_id, [
            'title_es' => "Jornada corta — {$employee->full_name}",
            'title_en' => "Short shift — {$employee->full_name}",
            'body_es' => "{$employee->full_name} ha fichado solo {$hoursLabel} hoy. No alcanza media jornada. Revisar si es correcto.",
            'body_en' => "{$employee->full_name} only clocked {$hoursLabel} today. Does not reach half day. Please review.",
            'entity' => $employee->full_name,
            'company' => $employee->company?->name,
            'url' => '/attendance',
        ]);
    }

    /** "2h 30m" from a decimal hours value, for the short-shift message. */
    private function hoursLabel(float $hours): string
    {
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);

        return "{$h}h {$m}m";
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
            $attendance = $this->attendance->createForWorker($employee, [
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
     * The weekend work offer that lets THIS employee punch on the given date, or
     * null. An offer exists per company per date; it only unlocks the day for the
     * workers on its invited list. Tenant scope dropped (workers have no CRM
     * session); membership is checked in PHP for MySQL/SQLite portability.
     */
    public function weekendOfferFor(Employee $employee, ?string $date = null): ?WeekendWorkOffer
    {
        $offer = WeekendWorkOffer::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $employee->company_id)
            ->whereDate('offer_date', $date ?? now()->toDateString())
            ->first();

        return ($offer !== null && $offer->invites($employee->id)) ? $offer : null;
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

    /**
     * Flag when check-out GPS is > 500 m from check-in GPS. Null when either
     * fix is unavailable (location_denied or no coordinates). GPS is evidence,
     * not a gate — the punch stands regardless.
     *
     * @param  array{lat: float|null, lng: float|null, accuracy: float|null, denied: bool}  $location
     */
    private function applyMismatch(Attendance $attendance, array $location): void
    {
        if ($location['denied'] || $location['lat'] === null || $location['lng'] === null) {
            return;
        }

        if ($attendance->check_in_lat === null || $attendance->check_in_lng === null) {
            return;
        }

        $metres = $this->haversine(
            (float) $attendance->check_in_lat,
            (float) $attendance->check_in_lng,
            $location['lat'],
            $location['lng'],
        );

        $attendance->location_mismatch = $metres > 500;
    }

    /** Haversine great-circle distance in metres. */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2.0 * $r * asin(sqrt($a));
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
