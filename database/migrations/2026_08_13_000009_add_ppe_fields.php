<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory rebuild — Phase D. PPE (EPIs) + compliance.
 *
 * Confirmed model:
 *  - An item can be flagged `is_ppe`, with a `default_expiry_date` that
 *    pre-fills the issue form (Q-D: expiry lives on the item AND the issue).
 *  - Each issue carries its own `expiry_date` (overridable per issue).
 *  - Required PPE is defined by CATEGORY (Q-B): a category flagged
 *    `is_required_ppe` must be held by every active worker; `height_only`
 *    marks a category (arnés) required ONLY for workers who work at height.
 *  - Employees carry `works_at_height` (Q-C) which turns on the height-only
 *    requirements for that worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->boolean('is_ppe')->default(false)->after('item_type');
            $table->date('default_expiry_date')->nullable()->after('is_ppe');
        });

        Schema::table('employee_equipment_issues', function (Blueprint $table): void {
            $table->date('expiry_date')->nullable()->after('expected_return_date');
        });

        Schema::table('equipment_categories', function (Blueprint $table): void {
            $table->boolean('is_required_ppe')->default(false)->after('active');
            $table->boolean('height_only')->default(false)->after('is_required_ppe');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('works_at_height')->default(false)->after('has_company_vehicle');
        });

        // Seed the standard Spanish construction EPIs as group-wide default
        // categories (company_id NULL), flagged required PPE. Arnés is required
        // only for height workers. Idempotent — skip a name that already exists
        // as a group default.
        $defaults = [
            ['name' => 'Casco de seguridad', 'height_only' => false],
            ['name' => 'Chaleco reflectante', 'height_only' => false],
            ['name' => 'Botas de seguridad', 'height_only' => false],
            ['name' => 'Guantes', 'height_only' => false],
            ['name' => 'Arnés', 'height_only' => true],
        ];

        foreach ($defaults as $d) {
            $exists = DB::table('equipment_categories')
                ->whereNull('company_id')
                ->where('name', $d['name'])
                ->exists();

            if (! $exists) {
                DB::table('equipment_categories')->insert([
                    'company_id' => null,
                    'name' => $d['name'],
                    'active' => true,
                    'is_required_ppe' => true,
                    'height_only' => $d['height_only'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->dropColumn(['is_ppe', 'default_expiry_date']);
        });
        Schema::table('employee_equipment_issues', function (Blueprint $table): void {
            $table->dropColumn('expiry_date');
        });
        Schema::table('equipment_categories', function (Blueprint $table): void {
            $table->dropColumn(['is_required_ppe', 'height_only']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('works_at_height');
        });
    }
};
