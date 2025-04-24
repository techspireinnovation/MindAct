<?php

namespace Database\Factories;

use App\Models\Company;

use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\Brand;
use App\Models\Location;
use App\Models\MeasureUnit;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word,
            'debit_note' => $this->faker->sentence(3),
            'credit_note' => $this->faker->sentence(3),
            'is_active' => $this->faker->boolean,
            'company_id' => Company::factory(), 
            'category_id' => ProductCategory::factory(),
            'brand_id' => Brand::factory(),
            'measure_unit_id' => MeasureUnit::factory(),
            'purchase_rate' => $this->faker->randomFloat(2, 10, 1000),
            'purchase_rate_vat' => $this->faker->randomFloat(2, 10, 1000),
            'retail_sales_price' => $this->faker->randomFloat(2, 10, 1000),
            'retail_sales_price_vat' => $this->faker->randomFloat(2, 10, 1000),
            'retail_sales_price_profit_percent' => $this->faker->randomFloat(2, 0, 100),
            'wholesales_price' => $this->faker->randomFloat(2, 10, 1000),
            'wholesales_price_vat' => $this->faker->randomFloat(2, 10, 1000),
            'wholesales_price_profit_percent' => $this->faker->randomFloat(2, 0, 100),
            'is_vatable' => $this->faker->boolean,
            'product_type_id' => ProductType::factory(),
            'location_id' => Location::factory(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
