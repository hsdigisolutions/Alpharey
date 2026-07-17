<?php

namespace Database\Factories;

use App\Enums\EquipmentItemType;
use App\Models\Company;
use App\Models\EquipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentItem>
 */
class EquipmentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Casco de seguridad',
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-####')),
            'item_type' => EquipmentItemType::Safety,
            'unit' => 'pcs',
            'total_stock' => '0',
            'available_stock' => '0',
            'minimum_stock' => '0',
            'active' => true,
        ];
    }

    /**
     * Stock counters are not mass assignable (the ledger owns them), so this
     * seeds them directly for tests that need an item to already hold stock.
     */
    public function withStock(string $quantity = '10'): static
    {
        return $this->afterCreating(function (EquipmentItem $item) use ($quantity): void {
            $item->total_stock = $quantity;
            $item->available_stock = $quantity;
            $item->save();
        });
    }
}
