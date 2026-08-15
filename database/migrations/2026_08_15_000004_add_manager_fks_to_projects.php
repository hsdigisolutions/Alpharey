<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project's people (site manager / foreman / safety / coordinator) become
 * links to EMPLOYEE records instead of free text. The old text columns
 * (jefe_de_obra / encargado / seguridad / coordinator, + jefe_phone/email) are
 * KEPT for backward-compatible display: when no *_id is set, the legacy text
 * still shows. nullOnDelete so removing an employee never breaks a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('site_manager_id')->nullable()->after('jefe_email')->constrained('employees')->nullOnDelete();
            $table->foreignId('foreman_id')->nullable()->after('site_manager_id')->constrained('employees')->nullOnDelete();
            $table->foreignId('safety_id')->nullable()->after('foreman_id')->constrained('employees')->nullOnDelete();
            $table->foreignId('coordinator_id')->nullable()->after('safety_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_manager_id');
            $table->dropConstrainedForeignId('foreman_id');
            $table->dropConstrainedForeignId('safety_id');
            $table->dropConstrainedForeignId('coordinator_id');
        });
    }
};
