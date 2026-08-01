<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker PWA extension: voice notes at check-out (Feature 1), worker-submitted
 * expenses (Feature 2), salary-advance display (Feature 3 — no schema change,
 * the advances table already exists), and vehicle check-in/out sessions
 * (Feature 4) with a per-employee vehicle-access flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Feature 1 — Voice / text note captured at check-out.
        Schema::create('attendance_voice_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // audio_path on the private disk; null = text-only note.
            $table->string('audio_path')->nullable();
            $table->text('text_note')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->timestamps();
        });

        // Feature 2 — Worker-submitted expense during or after check-out.
        Schema::create('worker_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // nullable — submitted outside a checkout is allowed
            $table->foreignId('attendance_id')->nullable()->constrained('attendance')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('amount', 10, 2);
            $table->string('category', 50)->default('other');
            $table->text('description');
            $table->string('receipt_path')->nullable();
            // pending → approved or rejected; approved → included in payroll via payroll_id
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('payroll_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Feature 4 — Vehicle session: a worker taking a company vehicle.
        Schema::create('vehicle_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->datetime('taken_at');
            $table->datetime('returned_at')->nullable();
            $table->unsignedInteger('starting_mileage');
            $table->unsignedInteger('ending_mileage')->nullable();
            $table->unsignedInteger('km_driven')->nullable();
            $table->tinyInteger('starting_fuel_level')->nullable(); // 0-100 %
            $table->tinyInteger('ending_fuel_level')->nullable();
            $table->decimal('fuel_added_litres', 6, 2)->nullable();
            $table->text('return_notes')->nullable();
            // Set when the 24-hour overdue alert fires so we don't double-send.
            $table->boolean('overdue_alerted')->default(false);
            $table->timestamps();
        });

        // Feature 4 — real-time availability flag on the vehicle.
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->boolean('is_available')->default(true)->after('active');
        });

        // Feature 4 — per-employee vehicle-access grant (toggled by Admin/Manager).
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('can_use_vehicles')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('can_use_vehicles');
        });
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('is_available');
        });
        Schema::dropIfExists('vehicle_sessions');
        Schema::dropIfExists('worker_expenses');
        Schema::dropIfExists('attendance_voice_notes');
    }
};
