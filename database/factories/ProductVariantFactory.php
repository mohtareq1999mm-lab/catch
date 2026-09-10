<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Marvel\Database\Models\ProductVariant;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition()
    {
        $price = $this->faker->randomFloat(2, 10, 100);
        $salePrice = $this->faker->boolean(30) ? round($price * (1 - $this->faker->randomFloat(2, 0.05, 0.3)), 2) : null;

        return [
            'sku' => 'VAR-' . strtoupper(Str::random(8)),
            'price' => $price,
            'sale_price' => $salePrice,
            'stock_quantity' => $this->faker->numberBetween(5, 100),
            'quantity' => $this->faker->numberBetween(5, 100),
            'reserved_quantity' => 0,
            'sold_quantity' => $this->faker->numberBetween(0, 30),
            'height' => (string) $this->faker->numberBetween(5, 30),
            'width' => (string) $this->faker->numberBetween(3, 20),
            'length' => (string) $this->faker->numberBetween(1, 15),
            'weight' => (string) $this->faker->numberBetween(10, 2000),
            'product_id' => null,
            'in_stock' => true,
        ];
    }
}
