<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy VertoCRM id → new id mapping. Written by the verto:import-legacy
 * importers so re-runs are idempotent (DATA_MIGRATION.md §2).
 */
class LegacyIdMap extends Model
{
    protected $table = 'legacy_id_map';

    protected $fillable = [
        'entity_type',
        'legacy_id',
        'new_id',
    ];
}
