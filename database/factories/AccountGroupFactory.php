<?php

namespace Database\Factories;
use App\Models\Company;

use App\Models\SubGroup;
use App\Models\MainGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccountGroup>
 */
class AccountGroupFactory extends Factory
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
            'sub_group_id' => SubGroup::factory(),
         
            'is_active' => $this->faker->boolean,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
