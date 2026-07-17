<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EquipmentItem;
use App\Models\EquipmentProjectAssignment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentProjectAssignment>
 */
class EquipmentProjectAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'equipment_item_id' => EquipmentItem::factory(),
            'project_id' => Project::factory(),
            'quantity' => '1',
            'start_date' => now()->toDateString(),
        ];
    }
}
