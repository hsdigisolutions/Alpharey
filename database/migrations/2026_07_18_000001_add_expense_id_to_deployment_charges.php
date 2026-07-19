<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a cross-company charge to the internal expense it posted on the
     * HOST company (PAYROLL_DEPLOYMENTS.md step 4).
     *
     * Without this link a re-run of the charge engine (completing a deployment
     * twice, or refreshing an existing charge) would post a second expense and
     * double-count the cost. With it, the engine updates the same row.
     *
     * Nullable: charges created before this migration — and any non-Option-A
     * arrangement, which never posts automatically — simply have none.
     */
    public function up(): void
    {
        Schema::table('deployment_charges', function (Blueprint $table): void {
            $table->foreignId('expense_id')->nullable()->after('project_id')
                ->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployment_charges', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
