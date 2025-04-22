<?php

namespace Database\Factories;

use App\Models\Company;

use App\Models\MainGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubGroup>
 */
class SubGroupFactory extends Factory
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
            'main_group_id' => MainGroup::factory(),
            'code' => $this->faker->unique()->word,
            'ranking_for_trial' => $this->faker->numberBetween(1, 100),
            'is_active' => $this->faker->boolean,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
