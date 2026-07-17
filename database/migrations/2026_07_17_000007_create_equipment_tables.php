<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 23 — Inventory / Equipment.
     *
     * Stock is physical, so items are company-owned: a helmet in Empresa 1's
     * store is not stock Empresa 2 can issue. Categories follow the
     * expense_categories shape (NULL company_id = a group-wide default).
     *
     * `available_stock` is a cached figure maintained by the movement service,
     * not an independent truth — equipment_stock_movements is the ledger, and
     * balance_after on each movement is what makes the running balance
     * auditable after the fact.
     */
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('sku', 60);
            $table->string('item_type', 20)->default('tool'); // EquipmentItemType
            $table->string('unit', 20)->default('pcs');

            $table->decimal('total_stock', 12, 2)->default(0);
            $table->decimal('available_stock', 12, 2)->default(0);
            $table->decimal('minimum_stock', 12, 2)->default(0);

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // SKU is unique per company, not per group — see plate_number
            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('equipment_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type', 20); // StockMovementType
            // Always positive; the movement TYPE carries the direction, so a
            // signed quantity can never disagree with it.
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2)->nullable();

            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['equipment_item_id', 'created_at']);
            $table->index(['company_id', 'movement_type']);
        });

        Schema::create('employee_equipment_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->cascadeOnDelete();

            $table->decimal('issued_quantity', 12, 2);
            $table->decimal('returned_quantity', 12, 2)->default(0);
            $table->date('issue_date');
            $table->date('expected_return_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('status', 30)->default('open'); // EquipmentIssueStatus

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index('expected_return_date'); // overdue sweep
        });

        Schema::create('equipment_project_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 12, 2)->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active'); // EquipmentAssignmentStatus

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_project_assignments');
        Schema::dropIfExists('employee_equipment_issues');
        Schema::dropIfExists('equipment_stock_movements');
        Schema::dropIfExists('equipment_items');
        Schema::dropIfExists('equipment_categories');
    }
};
