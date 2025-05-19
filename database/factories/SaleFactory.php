<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'store_id' => $this->faker->numberBetween(1, 10),
            'entry_type' => $this->faker->randomElement(['invoice', 'quotation']),
            'note' => $this->faker->sentence,
            'quotation_number' => $this->faker->word,
            'bill_number' => $this->faker->word,
            'tpin_number' => $this->faker->word,
            'billing_date' => $this->faker->date(),
            'location' => Location::factory(),
            'sale_rate_type' => $this->faker->randomElement(['retail', 'wholesale']),
            'discount' => $this->faker->randomFloat(2, 0, 100),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
