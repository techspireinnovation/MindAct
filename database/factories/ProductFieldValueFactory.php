<?php

namespace Database\Factories;


use App\Models\Company;
use App\Models\Product;
use App\Models\ProductField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductFieldValue>
 */
class ProductFieldValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'value' => $this->faker->unique()->word,
            'company_id' => Company::factory(),
            'product_field_id' => ProductField::factory(),
            'product_id' => Product::factory(),
           
            'created_at' => now(),
            'updated_at' => now(),
            
        ];
    }
}
