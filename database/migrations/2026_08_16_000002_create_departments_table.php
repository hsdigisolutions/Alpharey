<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-scoped departments catalogue (Settings → Departamentos). Each company
 * owns its own list; the employee form's Department field and the list filter
 * read from here instead of hardcoded/free-text values.
 *
 * Additive + safe for production: a new table, plus a per-company seed of the
 * seven standard departments (idempotent — skips any that already exist).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'Civil Works', 'Electrical', 'Plumbing', 'Painting', 'Finishing', 'Administration', 'Drivers',
    ];

    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'active']);
            $table->unique(['company_id', 'name']);
        });

        // Seed the seven defaults for every existing (non-deleted) company, so
        // production has them the moment this migration runs. Idempotent.
        $now = now();
        $companyIds = DB::table('companies')->whereNull('deleted_at')->pluck('id');
        foreach ($companyIds as $companyId) {
            foreach (self::DEFAULTS as $name) {
                $exists = DB::table('departments')
                    ->where('company_id', $companyId)->where('name', $name)->exists();
                if (! $exists) {
                    DB::table('departments')->insert([
                        'company_id' => $companyId,
                        'name' => $name,
                        'active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
