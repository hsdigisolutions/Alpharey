<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Worker trade types (Maestro, Peón, Electricista…). Reference data shaped like
 * leave/expense categories: a NULL company_id is a group-wide default; a company
 * may add its own. Employees point at one (designation_id) and a project can set
 * a per-designation rate (project_designation_rates).
 *
 * A nullable designation_id is also added to employees; the legacy free-text
 * `designation` column is kept for display + back-compat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 40);              // stable code (mistri, peon…)
            $table->string('name', 100);            // display label (Spanish)
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'active']);
            $table->unique(['company_id', 'key']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('designation_id')->nullable()->after('designation')
                ->constrained('designations')->nullOnDelete();
        });

        // 14 group-wide defaults (company_id null). Idempotent + production-safe.
        $now = now()->toDateTimeString();
        $defaults = [
            ['mistri', 'Maestro / Mistri'], ['peon', 'Peón / Mazdoor'],
            ['electricista', 'Electricista'], ['fontanero', 'Fontanero'],
            ['soldador', 'Soldador'], ['pintor', 'Pintor'],
            ['carpintero', 'Carpintero'], ['yesero', 'Yesero'],
            ['encofrador', 'Encofrador'], ['gruista', 'Gruista'],
            ['oficial_1', 'Oficial 1ª'], ['oficial_2', 'Oficial 2ª'],
            ['ayudante', 'Ayudante'], ['otro', 'Otro'],
        ];

        foreach ($defaults as $i => [$key, $name]) {
            DB::table('designations')->insertOrIgnore([
                'company_id' => null, 'key' => $key, 'name' => $name,
                'active' => true, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('designation_id');
        });

        Schema::dropIfExists('designations');
    }
};
