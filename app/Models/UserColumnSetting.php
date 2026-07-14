<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserColumnSetting extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'table_name', 'visible_columns'];

    protected function casts(): array
    {
        return [
            'visible_columns' => 'array',
        ];
    }

    /**
     * Saved column set for a table, or null (page uses its defaults).
     *
     * @return list<string>|null
     */
    public static function for(?User $user, string $table): ?array
    {
        if ($user === null) {
            return null;
        }

        /** @var list<string>|null */
        return self::query()
            ->where('user_id', $user->id)
            ->where('table_name', $table)
            ->value('visible_columns');
    }
}
