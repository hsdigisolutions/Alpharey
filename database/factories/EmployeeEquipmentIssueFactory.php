<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEquipmentIssue>
 */
class EmployeeEquipmentIssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'equipment_item_id' => EquipmentItem::factory(),
            'issued_quantity' => '1',
            'returned_quantity' => '0',
            'issue_date' => now()->toDateString(),
        ];
    }
}
