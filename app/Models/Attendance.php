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
 * @property bool $location_denied
 * @property bool|null $location_mismatch
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
        'total_amount', 'manual_wage_override', 'override_reason', 'is_paid', 'is_exception',
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
}
