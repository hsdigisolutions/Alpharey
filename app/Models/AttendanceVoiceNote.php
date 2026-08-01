<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional voice or text note attached to a check-out. Audio is stored on the
 * private disk; text_note is a fallback when the device has no microphone.
 * Downloads are gated — the worker downloads their own; admins need
 * attendance.view. audio_path is NOT mass assignable (set by the service).
 *
 * @property int $id
 * @property int $attendance_id
 * @property int $employee_id
 * @property int $company_id
 * @property string|null $audio_path
 * @property string|null $text_note
 * @property int|null $duration_seconds
 */
class AttendanceVoiceNote extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'attendance';

    /** @var list<string> */
    protected $fillable = [
        'attendance_id', 'employee_id', 'text_note', 'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
