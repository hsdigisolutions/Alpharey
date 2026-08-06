<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $called_at
 * @property Carbon|null $follow_up_date
 * @property string|null $remarks
 * @property string|null $voice_note_path
 * @property string|null $voice_note_label
 * @property string|null $attachment_path
 * @property string|null $attachment_original_name
 * @property string|null $attachment_label
 */
class EmployeeCallLog extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'call_panel';

    /** @var list<string> */
    protected $fillable = ['called_at', 'remarks', 'follow_up_date', 'voice_note_label', 'attachment_label'];

    /** Paths must never reach the client or the audit log. */
    /** @var list<string> */
    protected $hidden = ['voice_note_path', 'attachment_path'];

    /** @var list<string> */
    public array $auditExclude = ['voice_note_path', 'attachment_path'];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'follow_up_date' => 'date:Y-m-d',
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
     * @return BelongsTo<User, $this>
     */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }
}
