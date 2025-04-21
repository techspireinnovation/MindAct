<?php

namespace Database\Factories;
use App\Models\Company;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
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
            'company_id' => Company::factory(),
            'is_active' => $this->faker->boolean,
            'created_at' => now(),
            'updated_at' => now(),
            
        ];
    }
}
