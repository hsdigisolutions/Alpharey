<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The role rebuild renamed the role VALUES on users but missed the other
 * table that stores role strings: notification_role_rules (type × role →
 * enabled). Rows keyed by the old names were silently ignored by every
 * lookup, which reset any configured notification matrix to the coded
 * defaults. Rename them so the client's configuration survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE notification_role_rules SET role = 'admin' WHERE role = 'company_admin'");
        DB::statement("UPDATE notification_role_rules SET role = 'manager' WHERE role = 'user'");
    }

    public function down(): void
    {
        DB::statement("UPDATE notification_role_rules SET role = 'user' WHERE role = 'manager'");
        DB::statement("UPDATE notification_role_rules SET role = 'company_admin' WHERE role = 'admin'");
    }
};
