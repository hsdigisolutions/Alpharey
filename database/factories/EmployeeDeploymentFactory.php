<?php

namespace Database\Factories;

use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Enums\DeploymentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDeployment>
 */
class EmployeeDeploymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $home = Company::factory();
        $host = Company::factory();

        return [
            'employee_id' => Employee::factory(),
            'home_company_id' => $home,
            'host_company_id' => $host,
            'project_id' => Project::factory(),
            'deployment_start' => now()->subDays(10)->toDateString(),
            'deployment_end' => now()->addDays(20)->toDateString(),
            'billing_method' => BillingMethod::OptionA,
            'rate_during_deployment' => fake()->randomFloat(2, 10, 30),
            'rate_type' => DeploymentRateType::Hourly,
            'split_pct' => 100,
            'status' => DeploymentStatus::Active,
        ];
    }
}
