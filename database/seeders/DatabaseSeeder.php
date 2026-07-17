<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development seed: the Verto5 brand, 5 dummy companies
     * (placeholder names per DECISIONS.md — real names/CIFs/logos arrive
     * later and are editable from Settings), and one user per role.
     *
     * Local credentials (never used in production):
     *   admin@verto5.local / password             Super Admin
     *   empresa1.admin@verto5.local / password    Company Admin (Empresa Uno)
     *   empresa1.user@verto5.local / password     Custom user (Empresa Uno)
     */
    public function run(): void
    {
        $brand = Brand::query()->firstOrCreate(['name' => 'Verto5']);

        $provinces = [
            'Empresa Uno' => 'Madrid',
            'Empresa Dos' => 'Barcelona',
            'Empresa Tres' => 'Valencia',
            'Empresa Cuatro' => 'Sevilla',
            'Empresa Cinco' => 'Bizkaia',
        ];

        $companies = collect($provinces)->map(
            fn (string $province, string $name): Company => Company::query()->firstOrCreate(
                ['name' => $name],
                ['brand_id' => $brand->id, 'province' => $province, 'status' => 'active'],
            ),
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@verto5.local'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
                'locale' => 'es',
            ],
        );

        $first = $companies->first();

        User::query()->firstOrCreate(
            ['email' => 'empresa1.admin@verto5.local'],
            [
                'name' => 'Admin Empresa Uno',
                'password' => 'password',
                'role' => UserRole::CompanyAdmin,
                'company_id' => $first->id,
                'locale' => 'es',
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'empresa1.user@verto5.local'],
            [
                'name' => 'Usuario Empresa Uno',
                'password' => 'password',
                'role' => UserRole::User,
                'company_id' => $first->id,
                'locale' => 'es',
            ],
        );

        // Reference data, not dummies: the 8 leave categories ship with the
        // product and are needed in production too.
        $this->call(LeaveCategorySeeder::class);
    }
}
