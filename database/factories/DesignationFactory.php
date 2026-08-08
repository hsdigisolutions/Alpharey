<?php

namespace Database\Factories;

use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'company_id' => null,
            'key' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'active' => true,
            'sort' => 0,
        ];
    }
}
