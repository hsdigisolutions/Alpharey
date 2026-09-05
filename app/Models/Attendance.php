<?php

namespace App\Models;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Enums\WageType;
use App\Enums\WeekendRateType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
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
        // wage_type_snapshot / wage_rate_snapshot / hourly_rate_snapshot are the
        // frozen pay basis — set ONLY by AttendanceService/LeaveService/importer
        // via direct assignment, never mass-assigned, so they are deliberately
        // NOT fillable (a client must never set its own frozen rate). is_paid is
        // likewise server-owned (importer / payroll flows only).
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
        // Drop the tenant scope so a transferred-away employee (their single
        // record now lives at another company) still resolves for this
        // company's historical attendance — their name must not vanish from the
        // grid, project attendance or timesheets after a transfer. SoftDeletes
        // still applies; the parent row is company-owned, so this only names the
        // employee a row the caller may already see refers to (like deployments).
        return $this->belongsTo(Employee::class)->withoutGlobalScope(CompanyScope::class);
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

    /**
     * The standard NET full working day, in hours — 08:00–17:00 minus the 1 h
     * lunch break. Used only by displayHoursNet() to decide WHEN a full day's
     * clock span is long enough to contain the break (see below). It is a
     * DISPLAY threshold, never a pay figure.
     */
    public const STANDARD_FULL_DAY_HOURS = 8.0;

    /**
     * Day-type-aware NET hours for DISPLAY. Unlike displayHours() (raw time on
     * site), this takes the standard unpaid lunch break off a clerk-entered
     * FULL day whose clock span contains it — the new 08:00–17:00 shift — so it
     * reads 8 h, while leaving every recorded figure and all history honest.
     * The precedence, top to bottom:
     *   - absent / leave  → 0 h (a non-worked row has no hours, even if it
     *                       carries stray clock times).
     *   - open shift      → elapsed time so far (a worker still on site).
     *   - recorded hours  → `hours_worked` verbatim whenever it is set (> 0): a
     *                       worker punch and a legacy import carry their real
     *                       net hours, and an hourly day is already net of the
     *                       break (deduct_break), so it is never deducted twice.
     *   - clerk FULL day  → with `hours_worked` 0 but a real clock span: the
     *                       span, minus the break ONLY when removing it still
     *                       leaves a normal full working day (span − break ≥
     *                       STANDARD_FULL_DAY_HOURS). A 9 h 08:00–17:00 span → 8
     *                       h; an 8 h 09:00–17:00 or 8.5 h span is already net.
     *                       The per-company `break_duration_minutes` setting
     *                       (Step 1) sizes the break.
     *   - clerk half /
     *     per_meter        → the real clock span (a genuine short half day reads
     *                       its span), or the stored hours when there is no
     *                       clock either. No break, ever.
     *
     * DISPLAY ONLY. This never feeds pay and never rewrites `hours_worked`;
     * full/half pay is a fixed daily-rate formula independent of hours, and
     * hourly pay is computed from `hours_worked`, which this method never sets.
     *
     * @param  int|null  $breakMinutes  the company's break, if the caller has
     *                                  already resolved it once (avoids a per-row settings read when rendering
     *                                  a whole grid); null resolves it from this row's company.
     */
    public function displayHoursNet(?int $breakMinutes = null): float
    {
        // Only a WORKED day has hours to show: an absent or leave row reads 0 h,
        // even if it carries stray clock times (a since-changed status, or a
        // factory default). Callers that sum over every row in a month therefore
        // count only worked hours without pre-filtering.
        if (! in_array($this->status->value, ['present', 'late', 'early_leave'], true)) {
            return 0.0;
        }

        // Still on site (an open PWA shift) → elapsed time, like displayHours().
        if ($this->isOpenShift()) {
            return $this->displayHours();
        }

        // Recorded worked hours win whenever present: a worker punch and a
        // legacy import both carry their real net hours here, and an hourly
        // day's hours are already net of the break (deduct_break), so it is
        // never deducted a second time. This keeps every surface agreeing with
        // the stored figure and never rewrites it.
        $recorded = round((float) $this->hours_worked, 2);
        if ($recorded > 0.0) {
            return $recorded;
        }

        // No recorded hours (a clerk-entered full/half day) — derive them from
        // the clock. A FULL day takes off the break ONLY when the span is the
        // new 08:00–17:00 shift that contains it (removing it still leaves a
        // normal full working day, ≥ STANDARD_FULL_DAY_HOURS); an 8 h 09:00–17:00
        // span or an 8.5 h span is already net. This is why a clerk full day,
        // whose hours_worked is 0 but which carries an 08:00–17:00 span, reads
        // 8 h. The per-company `break_duration_minutes` setting sizes the break.
        if ($this->displayDayType() === DayType::Full
            && $this->check_in !== null
            && $this->check_out !== null
        ) {
            $gross = self::clockSpanHours($this->check_in, $this->check_out);
            $minutes = $breakMinutes ?? app(AttendanceService::class)
                ->breakDurationMinutes((int) $this->company_id);
            $net = $gross - $minutes / 60;

            return round($net >= self::STANDARD_FULL_DAY_HOURS ? $net : $gross, 2);
        }

        // half / per_meter with no recorded hours → the real clock span (a
        // genuine short half day reads its span), no break ever; or the stored
        // hours as a last resort when there is no clock either.
        return $this->displayHours();
    }

    /**
     * The day type used for the display math, mirroring the payroll fallback
     * (PayrollService::effectiveDayType) so a legacy row with a null day_type
     * is graded the same way here as it is when it is paid.
     */
    private function displayDayType(): DayType
    {
        if ($this->day_type !== null) {
            return $this->day_type;
        }

        return match ($this->wage_type_snapshot) {
            WageType::Daily => DayType::Full,
            WageType::PerMeter => DayType::PerMeter,
            default => DayType::Hourly,
        };
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
