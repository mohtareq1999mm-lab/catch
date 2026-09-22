<?php

namespace Database\Factories\Fulfillment;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'parent_id' => null,
            'code' => strtoupper($this->faker->unique()->bothify('?-##')),
            'name' => 'Shelf ' . strtoupper($this->faker->bothify('?-##')),
            'type' => $this->faker->randomElement(['shelf', 'bin', 'zone']),
            'status' => 'active',
            'priority' => $this->faker->numberBetween(1, 10),
            'metadata' => null,
        ];
    }
}
