<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-project invoice reminder schedule (Screen 10). The schedule is stored
 * here in Phase 6; the actual sending is wired in Phase 8 with the other
 * scheduled mail.
 *
 * @property int $company_id
 * @property string $month
 * @property array<int, string>|null $reminder_emails
 */
class InvoiceReminder extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'invoices';

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'month', 'period_start', 'period_end',
        'reminder_days', 'reminder_emails',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'last_sent_at' => 'datetime',
            'reminder_emails' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
