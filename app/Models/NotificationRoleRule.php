<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Screen 26 — one cell of the notification matrix. Group-wide (no company_id):
 * notification policy is a brand-level decision (see the migration).
 *
 * @property int $id
 * @property string $notification_type
 * @property string $role
 * @property bool $enabled
 */
class NotificationRoleRule extends Model
{
    use Auditable;

    public string $auditModule = 'settings';

    /** @var list<string> */
    protected $fillable = ['notification_type', 'role', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
