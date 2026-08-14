<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory rebuild — Phase E. Damage & loss incidents. When a worker returns
 * kit Damaged or Lost, the unit is written off (total −q through the ledger) and
 * an incident is recorded against the responsible worker — condition, date,
 * quantity, notes. Recorded ONLY (client decision Q4): no auto-expense, no
 * payroll deduction; the admin decides separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_equipment_issue_id')->nullable()->constrained()->nullOnDelete();

            $table->date('incident_date');
            $table->string('condition', 20); // EquipmentReturnCondition: damaged | lost
            $table->decimal('quantity', 12, 2)->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'incident_date']);
            $table->index(['employee_id', 'incident_date']);
            $table->index('equipment_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_incidents');
    }
};
