<?php

namespace Database\Factories\Fulfillment;

use App\Models\Fulfillment\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('WH-???')),
            'name' => $this->faker->company . ' Warehouse',
            'address' => $this->faker->streetAddress,
            'city' => $this->faker->city,
            'country' => $this->faker->country,
            'status' => 'active',
            'is_default' => false,
            'metadata' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
