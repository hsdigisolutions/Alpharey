<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebrand Verto5 → AlphaRey, and replace the 5 placeholder company names with
 * the group's real ones (client-confirmed 2026-07-22).
 *
 * A code-only rename would leave an existing install still showing "Verto5":
 * the brand name, the `general.app_name` setting and the company names all
 * live in the DATABASE. This migration moves that stored data.
 *
 * Every write is conditional on the old value, so it is safe on a fresh
 * install (nothing matches — no-op) and safe to run against a database an
 * operator has already renamed by hand.
 */
return new class extends Migration
{
    /** Placeholder → real name (DECISIONS.md: names are data, editable later). */
    private const COMPANY_RENAMES = [
        'Empresa Uno' => 'Contalex 365',
        'Empresa Dos' => 'Alovar',
        'Empresa Tres' => 'Shizukani',
        'Empresa Cuatro' => 'Grupo Verto 5',
        'Empresa Cinco' => 'Malaga',
    ];

    public function up(): void
    {
        DB::table('brands')->where('name', 'Verto5')->update(['name' => 'AlphaRey']);

        foreach (self::COMPANY_RENAMES as $old => $new) {
            DB::table('companies')->where('name', $old)->update(['name' => $new]);
        }

        // Settings values are JSON-encoded (SettingsService), so the stored
        // value is the quoted string, not the bare one.
        DB::table('settings')
            ->where('key', 'general.app_name')
            ->update(['value' => json_encode('AlphaRey')]);

        DB::table('settings')
            ->where('key', 'mail.from_name')
            ->where('value', json_encode('Verto5'))
            ->update(['value' => json_encode('AlphaRey')]);
    }

    /**
     * Reversible so a rollback during cutover does not strand the old install
     * on a half-renamed database.
     */
    public function down(): void
    {
        DB::table('brands')->where('name', 'AlphaRey')->update(['name' => 'Verto5']);

        foreach (self::COMPANY_RENAMES as $old => $new) {
            DB::table('companies')->where('name', $new)->update(['name' => $old]);
        }

        DB::table('settings')
            ->where('key', 'general.app_name')
            ->update(['value' => json_encode('Verto5')]);

        DB::table('settings')
            ->where('key', 'mail.from_name')
            ->where('value', json_encode('AlphaRey'))
            ->update(['value' => json_encode('Verto5')]);
    }
};
