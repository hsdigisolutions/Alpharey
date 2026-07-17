<?php

namespace Database\Seeders;

use App\Models\LeaveCategory;
use Illuminate\Database\Seeder;

class LeaveCategorySeeder extends Seeder
{
    /**
     * The 8 default leave categories (REQUIREMENTS.md Screen 22), seeded with
     * NULL company_id so every company shares them; each company can still add
     * its own on top from Settings.
     *
     * These are reference data, not demo data — idempotent, and safe on a
     * production deploy. Allocations are the client's stated defaults and are
     * editable from Settings, so changing one here later will not overwrite a
     * company that has adjusted it.
     *
     * Unpaid leave is marked is_paid = false: it still books the day in
     * attendance, but must not pay for it.
     */
    public function run(): void
    {
        $categories = [
            ['key' => 'casual', 'name' => 'Permiso Ocasional', 'default_allocation' => '12', 'is_paid' => true],
            ['key' => 'annual', 'name' => 'Vacaciones Anuales', 'default_allocation' => '20', 'is_paid' => true],
            ['key' => 'sick', 'name' => 'Baja por Enfermedad', 'default_allocation' => '10', 'is_paid' => true],
            ['key' => 'maternity', 'name' => 'Permiso de Maternidad', 'default_allocation' => '90', 'is_paid' => true],
            ['key' => 'paternity', 'name' => 'Permiso de Paternidad', 'default_allocation' => '15', 'is_paid' => true],
            ['key' => 'unpaid', 'name' => 'Permiso sin Sueldo', 'default_allocation' => '0', 'is_paid' => false],
            ['key' => 'compensatory', 'name' => 'Descanso Compensatorio', 'default_allocation' => '0', 'is_paid' => true],
            ['key' => 'other', 'name' => 'Otros', 'default_allocation' => '0', 'is_paid' => true],
        ];

        foreach ($categories as $category) {
            LeaveCategory::query()->firstOrCreate(
                ['company_id' => null, 'key' => $category['key']],
                [
                    'name' => $category['name'],
                    'default_allocation' => $category['default_allocation'],
                    'is_paid' => $category['is_paid'],
                    'active' => true,
                ],
            );
        }
    }
}
