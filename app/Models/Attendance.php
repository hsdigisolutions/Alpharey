<?php

namespace App\Models;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Enums\WageType;
use App\Enums\WeekendRateType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Screen 11 — Attendance. Company-owned. The *_snapshot columns freeze the
 * employee's wage at entry time; payroll consumes these, never the live rate.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property Carbon $date
 * @property AttendanceMode $mode
 * @property DayType|null $day_type
 * @property DayType|null $auto_day_type
 * @property bool $is_auto_detected
 * @property bool $is_weekend
 * @property WeekendRateType|null $weekend_rate_type
 * @property numeric-string|null $weekend_rate_amount
 * @property AttendanceStatus $status
 * @property WageType|null $wage_type_snapshot
 * @property numeric-string $hours_worked
 * @property numeric-string|null $quantity
 * @property numeric-string $overtime_hours
 * @property numeric-string $total_amount
 * @property bool $manual_wage_override
 * @property bool $is_auto_generated
 * @property bool $is_paid
 * @property Carbon|null $check_in_at
 * @property Carbon|null $check_out_at
 * @property numeric-string|null $check_in_lat
 * @property numeric-string|null $check_in_lng
 * @property numeric-string|null $check_out_lat
 * @property numeric-string|null $check_out_lng
 * @property string|null $check_in_photo_path
 * @property string|null $check_out_attachment_path
 * @property string|null $check_out_attachment_name
 * @property string|null $check_out_attachment_2_path
 * @property string|null $check_out_attachment_2_name
 * @property string|null $check_out_attachment_3_path
 * @property string|null $check_out_attachment_3_name
 * @property bool $location_denied
 * @property bool|null $location_mismatch
 * @property numeric-string|null $distance_from_project
 * @property string|null $worker_note
 * @property string $source
 */
class Attendance extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $table = 'attendance';

    public string $auditModule = 'attendance';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'project_id', 'date', 'mode', 'day_type', 'check_in', 'check_out',
        'break_hours', 'deduct_break', 'hours_worked', 'quantity', 'overtime_hours', 'status',
        'weekend_rate_type', 'weekend_rate_amount',
        'wage_type_snapshot', 'wage_rate_snapshot', 'hourly_rate_snapshot',
        // is_paid is server-owned (set by the importer / payroll flows only) —
        // a client must never be able to flip a row's paid flag.
        'total_amount', 'manual_wage_override', 'override_reason', 'is_exception',
        'exception_reason', 'work_mode', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'mode' => AttendanceMode::class,
            'day_type' => DayType::class,
            'auto_day_type' => DayType::class,
            'is_auto_detected' => 'boolean',
            'is_weekend' => 'boolean',
            'weekend_rate_type' => WeekendRateType::class,
            'weekend_rate_amount' => 'decimal:2',
            'status' => AttendanceStatus::class,
            'wage_type_snapshot' => WageType::class,
            'break_hours' => 'decimal:2',
            'deduct_break' => 'boolean',
            'hours_worked' => 'decimal:2',
            'quantity' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'wage_rate_snapshot' => 'decimal:2',
            'hourly_rate_snapshot' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'manual_wage_override' => 'boolean',
            'is_auto_generated' => 'boolean',
            'is_paid' => 'boolean',
            'is_exception' => 'boolean',
            // Worker PWA capture (set by WorkerAttendanceService, never mass
            // assigned — a phone must not be able to POST a GPS coordinate into
            // a field the clerk grid never touches).
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_lat' => 'decimal:7',
            'check_in_lng' => 'decimal:7',
            'check_in_accuracy' => 'decimal:2',
            'check_out_lat' => 'decimal:7',
            'check_out_lng' => 'decimal:7',
            'check_out_accuracy' => 'decimal:2',
            'location_denied' => 'boolean',
            'location_mismatch' => 'boolean',
            'distance_from_project' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasOne<AttendanceVoiceNote, $this>
     */
    public function voiceNote(): HasOne
    {
        return $this->hasOne(AttendanceVoiceNote::class);
    }

    /**
     * REAL hours on site, for DISPLAY — the authoritative clock span, not the
     * pay field `hours_worked`. `hours_worked` is only computed from the clock
     * for hourly-mode rows (AttendanceService::recompute), so a clerk-entered
     * full/half day — the common case — keeps `hours_worked = 0` even though it
     * carries real check-in/out times. So the honest displayed hours must be
     * derived from the clock:
     *   - both times present → the gross span check_out − check_in (matches the
     *     existing correctly-filled full-day rows, which read 8h for 09:00–17:00);
     *   - checked in but not out yet (open PWA shift) → hours elapsed so far;
     *   - neither → the stored `hours_worked` as a last resort.
     * Break is NOT deducted here — this is time-on-site for an operational view,
     * consistent with the full-day rows that already read the gross span.
     */
    public function displayHours(): float
    {
        if ($this->check_in !== null && $this->check_out !== null) {
            return self::clockSpanHours($this->check_in, $this->check_out);
        }

        if ($this->isOpenShift()) {
            $start = $this->check_in_at
                ?? Carbon::parse($this->date->toDateString().' '.$this->check_in);

            return round(max(0.0, $start->diffInMinutes(Carbon::now()) / 60), 2);
        }

        return round((float) $this->hours_worked, 2);
    }

    /** Checked in but with no check-out yet — still working. */
    public function isOpenShift(): bool
    {
        return $this->check_in !== null && $this->check_out === null;
    }

    /** Gross span between two "HH:MM" clock strings, in hours, floored at 0. */
    public static function clockSpanHours(string $in, string $out): float
    {
        [$inH, $inM] = array_map('intval', explode(':', $in));
        [$outH, $outM] = array_map('intval', explode(':', $out));
        $minutes = ($outH * 60 + $outM) - ($inH * 60 + $inM);

        return round(max(0, $minutes) / 60, 2);
    }
}
