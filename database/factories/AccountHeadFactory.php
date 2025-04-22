<?php

namespace Database\Factories;
use App\Models\Company;
use App\Models\AccountGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccountHead>
 */
class AccountHeadFactory extends Factory
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
            'account_group_id' => AccountGroup::factory(),
            'code' => $this->faker->unique()->word,         
            'is_active' => $this->faker->boolean,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
