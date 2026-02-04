<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = $this->faker->randomFloat(2, 10, 500);

        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),
            'short_description' => $this->faker->sentence(),
            'price' => $price,
            'sale_price' => null,
            'currency' => 'MYR',
            'sku' => $this->faker->unique()->bothify('SKU-####-???'),
            'stock_status' => 'in_stock',
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'brand' => $this->faker->company(),
            'is_active' => true,
            'is_featured' => false,
            'tags' => [],
            'specifications' => [],
        ];
    }

    /**
     * Indicate that the product is featured.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Indicate that the product is on sale.
     */
    public function onSale(float $discountPercent = 20): static
    {
        return $this->state(function (array $attributes) use ($discountPercent) {
            $price = $attributes['price'] ?? 100;
            $salePrice = $price * (1 - $discountPercent / 100);

            return [
                'sale_price' => round($salePrice, 2),
            ];
        });
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_status' => 'out_of_stock',
            'stock_quantity' => 0,
        ]);
    }

    /**
     * Indicate that the product has a specific price.
     */
    public function withPrice(float $price): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => $price,
        ]);
    }
}
