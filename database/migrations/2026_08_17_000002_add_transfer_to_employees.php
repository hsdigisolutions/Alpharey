<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee transfer between companies. The employee's company_id moves to the
 * new company; these columns record where they came from (so the OLD company
 * keeps a read-only history view) and that their documents must be re-uploaded
 * for the new company. Attendance + payroll rows keep their own company_id and
 * stay with the old company. Additive + safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('previous_company_id')->nullable()->after('company_id')
                ->constrained('companies')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable()->after('previous_company_id');
            $table->boolean('documents_pending_reupload')->default(false)->after('transferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('previous_company_id');
            $table->dropColumn(['transferred_at', 'documents_pending_reupload']);
        });
    }
};
