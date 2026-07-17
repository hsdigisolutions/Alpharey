<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\EquipmentItem;
use App\Models\EquipmentStockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentStockMovement>
 */
class EquipmentStockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'equipment_item_id' => EquipmentItem::factory(),
            'movement_type' => StockMovementType::StockIn,
            'quantity' => '10',
        ];
    }
}
