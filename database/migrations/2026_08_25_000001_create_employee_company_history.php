<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change 2 REDESIGN — single-record transfer model.
 *
 * A transfer no longer creates a new employee record (that stranded attendance
 * history on superseded records and multiplied records on round trips). Instead
 * there is exactly ONE employee record per person, and its company_id is flipped
 * in place on transfer. This table records each COMPANY STINT so the Employment
 * History tab can still show "Company X (dates) → Company Y (dates) → current",
 * and so a stint's attendance can be queried by company_id + the date window.
 *
 * ended_at NULL = the current stint. Cross-company by design (spans two
 * companies, like employee_deployments) — no tenant scope.
 *
 * Backfill: open a current stint for every LIVE (non-soft-deleted) employee,
 * started at the earliest of their joining date, first attendance day, and
 * creation date, so the current stint window covers all their existing
 * attendance. Additive + safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_company_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies');
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'ended_at']);
            $table->index(['company_id', 'ended_at']);
        });

        // Backfill one open (current) stint per live employee.
        $employees = DB::table('employees')
            ->whereNull('deleted_at')
            ->get(['id', 'company_id', 'joining_date', 'created_at']);

        foreach ($employees as $e) {
            if ($e->company_id === null) {
                continue;
            }

            $firstAttendance = DB::table('attendance')
                ->where('employee_id', $e->id)
                ->min('date');

            // Earliest known day for this person, so the stint covers all their
            // attendance. Falls back to creation date when nothing else is set.
            $candidates = array_filter([
                $e->joining_date,
                $firstAttendance,
                $e->created_at,
            ]);
            $startedAt = $candidates === [] ? now()->toDateString() : min($candidates);
            $startedAt = substr((string) $startedAt, 0, 10);

            DB::table('employee_company_history')->insert([
                'employee_id' => $e->id,
                'company_id' => $e->company_id,
                'started_at' => $startedAt,
                'ended_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_company_history');
    }
};
