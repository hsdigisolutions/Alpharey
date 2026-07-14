<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structure only in Phase 2 (employees reference policies); the
     * management UI arrives with the Settings section in Phase 4.
     */
    public function up(): void
    {
        Schema::create('overtime_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // percentage | fixed_hourly | accumulate_days | none
            $table->string('type', 30)->default('none');
            $table->decimal('rate', 8, 2)->nullable();
            $table->decimal('daily_threshold_hours', 5, 2)->default(9.00);
            $table->decimal('accumulate_hours_per_day', 5, 2)->default(8.00);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_policies');
    }
};
