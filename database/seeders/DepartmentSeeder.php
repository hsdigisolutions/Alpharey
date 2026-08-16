<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * The seven standard departments for every company. Idempotent (firstOrCreate),
 * so it is safe to re-run and safe in production. The tenancy scope is dropped
 * because a seeder has no acting-company session; company_id is set explicitly.
 */
class DepartmentSeeder extends Seeder
{
    private const DEFAULTS = [
        'Civil Works', 'Electrical', 'Plumbing', 'Painting', 'Finishing', 'Administration', 'Drivers',
    ];

    public function run(): void
    {
        foreach (Company::query()->get() as $company) {
            foreach (self::DEFAULTS as $name) {
                Department::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $name],
                    ['active' => true],
                );
            }
        }
    }
}
