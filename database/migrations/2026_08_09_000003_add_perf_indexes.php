<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for two hot filters the audit flagged as unindexed:
 * attendance.status (payroll/P&L filter worked vs absent per company) and
 * expenses.approved (profitability sums approved project expenses). Both are
 * low-cardinality, so they only help combined with company_id/project_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->index(['company_id', 'status'], 'attendance_company_status_idx');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->index(['project_id', 'approved'], 'expenses_project_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropIndex('attendance_company_status_idx');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex('expenses_project_approved_idx');
        });
    }
};
