<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 21 — Vehicles (4 tabs).
     *
     * `insurance_expiry_date` and `ita_expiry_date` are not decoration: they
     * feed the same compliance/alert machinery as employee and company
     * documents (DEVELOPMENT_PLAN Phase 7), which is why they are indexed —
     * the nightly scan sweeps them by date across every company.
     *
     * Naming kept from the legacy schema on purpose: `ita_expiry_date` (the
     * Spanish roadworthiness test is normally abbreviated ITV, so this looks
     * like a legacy typo — renaming it is a one-line change once confirmed,
     * and is not worth silently diverging from the dump before then).
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number', 20);
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();

            $table->string('ownership', 20)->default('company'); // VehicleOwnership
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('active')->default(true);

            $table->string('fuel_type', 20)->nullable(); // FuelType
            $table->string('color', 40)->nullable();
            $table->string('vin_number', 40)->nullable();

            $table->string('insurance_policy_number', 60)->nullable();
            $table->date('insurance_expiry_date')->nullable();
            $table->date('ita_expiry_date')->nullable();
            $table->date('purchase_date')->nullable();

            $table->unsignedInteger('current_mileage')->nullable();
            $table->unsignedInteger('last_oil_change_mileage')->nullable();
            $table->date('last_oil_change_date')->nullable();
            $table->unsignedInteger('oil_change_interval_km')->nullable();
            $table->date('next_service_date')->nullable();
            $table->date('last_tyre_change_date')->nullable();
            $table->unsignedInteger('last_tyre_change_mileage')->nullable();

            $table->decimal('maintenance_cost_total', 12, 2)->default(0);
            $table->text('maintenance_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // A plate is unique to the fleet that owns it, not to the group:
            // two companies must never collide, but the same plate cannot be
            // registered twice inside one company.
            $table->unique(['company_id', 'plate_number']);
            $table->index(['company_id', 'active']);
            // the compliance scan reads these two across all companies
            $table->index('insurance_expiry_date');
            $table->index('ita_expiry_date');
        });

        /**
         * Tab: Historial de Asignación — who held THIS vehicle, and when.
         */
        Schema::create('vehicle_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('assigned_from');
            $table->dateTime('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'assigned_from']);
        });

        /**
         * The mirror of vehicle_history, and NOT a duplicate of it: this is the
         * employee's side, and `vehicle_id` is nullable because type='own'
         * records a worker using their own car — which by definition is not in
         * the fleet and has no vehicles row to point at.
         */
        Schema::create('employee_vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('company'); // VehicleAssignmentType
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_to']);
        });

        Schema::create('vehicle_maintenance_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('maintenance_type', 50);
            $table->date('maintenance_date');
            $table->unsignedInteger('vehicle_km')->nullable();
            $table->text('description')->nullable();
            $table->string('tyre_position', 50)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'maintenance_date']);
        });

        Schema::create('vehicle_mileage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('mileage_value');
            $table->date('recorded_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_mileage_histories');
        Schema::dropIfExists('vehicle_maintenance_histories');
        Schema::dropIfExists('employee_vehicle_assignments');
        Schema::dropIfExists('vehicle_history');
        Schema::dropIfExists('vehicles');
    }
};
