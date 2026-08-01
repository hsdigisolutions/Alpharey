<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle module extension:
 *  1. vehicles — add vehicle_type, road_tax_expiry_date
 *  2. vehicle_maintenance_histories — add vendor_name
 *  3. vehicle_daily_assignments — who had a vehicle on a given date
 *  4. vehicle_fines — traffic fines linked to a plate/driver
 *  5. vehicle_fuel_records — fill-up log
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('vehicle_type', 30)->nullable()->after('plate_number');
            $table->date('road_tax_expiry_date')->nullable()->after('ita_expiry_date');
        });

        Schema::table('vehicle_maintenance_histories', function (Blueprint $table): void {
            $table->string('vendor_name', 100)->nullable()->after('description');
        });

        Schema::create('vehicle_daily_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->date('assigned_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['vehicle_id', 'assigned_date']);
        });

        Schema::create('vehicle_fines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->date('fine_date');
            $table->decimal('amount', 10, 2);
            $table->string('description', 500);
            $table->string('authority', 100)->nullable();
            $table->string('file_path')->nullable();
            // company = posted to expenses; employee = noted on record (manual payroll deduction)
            $table->string('charged_to', 20)->default('company');
            $table->boolean('paid')->default(false);
            $table->date('paid_at')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('vehicle_fuel_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->date('fuel_date');
            $table->decimal('litres', 8, 2);
            $table->decimal('cost_per_litre', 6, 3);
            $table->decimal('total_cost', 10, 2);
            $table->integer('mileage_at_fill')->nullable();
            $table->string('payment_method', 30)->nullable(); // cash | card | company_card
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_fuel_records');
        Schema::dropIfExists('vehicle_fines');
        Schema::dropIfExists('vehicle_daily_assignments');

        Schema::table('vehicle_maintenance_histories', function (Blueprint $table): void {
            $table->dropColumn('vendor_name');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['vehicle_type', 'road_tax_expiry_date']);
        });
    }
};
