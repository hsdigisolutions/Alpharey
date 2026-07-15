<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 11 — Attendance. Company-owned. Wage/rate SNAPSHOTS are frozen
     * at entry time (wage_type_snapshot / wage_rate_snapshot / hourly_rate_
     * snapshot) so payroll reads the day's historical rate, never the
     * employee's current one. Table name is singular `attendance`.
     */
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('mode', 20)->default('hourly'); // AttendanceMode

            // Hourly mode
            $table->string('check_in', 5)->nullable();
            $table->string('check_out', 5)->nullable();
            $table->decimal('break_hours', 5, 2)->default(1);
            $table->boolean('deduct_break')->default(true);

            $table->decimal('hours_worked', 6, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->string('status', 20)->default('present'); // AttendanceStatus

            // Frozen wage snapshots (never recomputed)
            $table->string('wage_type_snapshot', 20)->nullable();
            $table->decimal('wage_rate_snapshot', 10, 2)->nullable();
            $table->decimal('hourly_rate_snapshot', 10, 2)->nullable();

            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('manual_wage_override')->default(false);
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_exception')->default(false);
            $table->string('exception_reason')->nullable();
            $table->string('work_mode', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One row per employee per day
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
            $table->index(['project_id', 'date']);
        });

        // Change log for attendance edits (who changed what, when)
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20); // created | updated
            $table->json('changes')->nullable();
            $table->timestamp('created_at');

            $table->index('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('attendance');
    }
};
