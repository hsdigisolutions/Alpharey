<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContact extends Model
{
    use Auditable;

    public string $auditModule = 'clients';

    /** @var list<string> */
    protected $fillable = ['name', 'designation', 'email', 'phone', 'alternate_phone'];

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
