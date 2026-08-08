<?php

namespace Database\Factories;

use App\Enums\ProjectRateType;
use App\Models\ProjectDesignationRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectDesignationRate>
 */
class ProjectDesignationRateFactory extends Factory
{
    protected $model = ProjectDesignationRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_rate' => '20',
            'worker_rate' => '15',
            'rate_type' => ProjectRateType::PerHour,
        ];
    }
}
