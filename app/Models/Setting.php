<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use Auditable;

    public string $auditModule = 'settings';

    protected $fillable = [
        'key',
        'value',
    ];
}
