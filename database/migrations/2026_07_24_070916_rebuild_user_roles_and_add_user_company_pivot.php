<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the user role system:
 *  - Renames 'company_admin' → 'admin' and 'user' → 'manager' in users
 *  - Creates the user_company pivot for multi-company assignment
 *  - Seeds the pivot from the existing company_id on each user row
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'company_admin'");
        DB::statement("UPDATE users SET role = 'manager' WHERE role = 'user'");

        Schema::create('user_company', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'company_id']);
        });

        // Populate pivot from the existing single-company assignment.
        // Use PHP iteration so SQLite (tests) and MySQL (production) both work.
        DB::table('users')
            ->whereNotNull('company_id')
            ->get(['id', 'company_id'])
            ->each(function (object $u): void {
                DB::table('user_company')->insertOrIgnore([
                    'user_id' => $u->id,
                    'company_id' => $u->company_id,
                    'created_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_company');

        DB::statement("UPDATE users SET role = 'user' WHERE role = 'manager'");
        DB::statement("UPDATE users SET role = 'company_admin' WHERE role = 'admin'");
    }
};
