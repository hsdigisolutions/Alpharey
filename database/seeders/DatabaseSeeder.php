<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development seed: the AlphaRey brand, the group's 5 REAL companies
     * (client-confirmed 2026-07-22, replacing the Empresa Uno…Cinco
     * placeholders), and one user per role.
     *
     * Local credentials (never used in production):
     *   admin@alpharey.local / password             Super Admin
     *   empresa1.admin@alpharey.local / password    Company Admin (Contalex 365)
     *   empresa1.user@alpharey.local / password     Custom user (Contalex 365)
     */
    public function run(): void
    {
        $brand = Brand::query()->firstOrCreate(['name' => 'AlphaRey']);

        $provinces = [
            'Contalex 365' => 'Madrid',
            'Alovar' => 'Barcelona',
            'Shizukani' => 'Valencia',
            'Grupo Verto 5' => 'Sevilla',
            'Malaga' => 'Bizkaia',
        ];

        $companies = collect($provinces)->map(
            fn (string $province, string $name): Company => Company::query()->firstOrCreate(
                ['name' => $name],
                ['brand_id' => $brand->id, 'province' => $province, 'status' => 'active'],
            ),
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@alpharey.local'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
                'locale' => 'es',
            ],
        );

        $first = $companies->first();

        $adminUser = User::query()->firstOrCreate(
            ['email' => 'empresa1.admin@alpharey.local'],
            [
                'name' => 'Admin Contalex 365',
                'password' => 'password',
                'role' => UserRole::Admin,
                'company_id' => $first->id,
                'locale' => 'es',
            ],
        );

        $managerUser = User::query()->firstOrCreate(
            ['email' => 'empresa1.user@alpharey.local'],
            [
                'name' => 'Manager Contalex 365',
                'password' => 'password',
                'role' => UserRole::Manager,
                'company_id' => $first->id,
                'locale' => 'es',
            ],
        );

        // Seed the user_company pivot for non-SA users
        foreach ([$adminUser, $managerUser] as $u) {
            if ($u->company_id !== null) {
                DB::table('user_company')->insertOrIgnore([
                    'user_id' => $u->id,
                    'company_id' => $u->company_id,
                    'created_at' => now(),
                ]);
            }
        }

        // Reference data, not dummies: the 8 leave categories ship with the
        // product and are needed in production too.
        $this->call(LeaveCategorySeeder::class);
    }
}
