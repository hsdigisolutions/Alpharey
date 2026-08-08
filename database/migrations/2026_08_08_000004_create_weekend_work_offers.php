<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekend work offers. Saturday/Sunday are days off by default — a worker
 * cannot punch in. An admin publishes an OFFER for a specific weekend date
 * (project + weekend rate + the invited workers); only then, and only for the
 * invited workers, does the PWA allow a check-in that day.
 *
 * One offer per company per date (updateOrCreate). `invited_employee_ids` is a
 * JSON array of employee ids checked in PHP (portable across MySQL/SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekend_work_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('offer_date');
            $table->string('weekend_rate_type', 20)->default('normal'); // WeekendRateType
            $table->decimal('weekend_rate_amount', 10, 2)->nullable();
            $table->json('invited_employee_ids');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'offer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekend_work_offers');
    }
};
