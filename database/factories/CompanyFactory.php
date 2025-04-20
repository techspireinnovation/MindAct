<?php

namespace Database\Factories;

use App\Models\Company;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'email_address' => $this->faker->unique()->safeEmail,
            'licence_issue_date' => $this->faker->date,
            'working_date' => $this->faker->date,
            'reg_number' => $this->faker->numerify('REG-#####'),
            'full_address' => $this->faker->address,
            'website' => $this->faker->url,
            'fax' => $this->faker->phoneNumber,
            'logo' => $this->faker->imageUrl(),
            'province' => $this->faker->state,
            'district' => $this->faker->city,
            'palika_name' => $this->faker->word,
            'ward_number' => $this->faker->numberBetween(1, 50),
            'contact_number' => $this->faker->phoneNumber,
            'contact_person' => $this->faker->name,
            'contact_person_position' => $this->faker->jobTitle,
            'agreement_holder_name' => $this->faker->name,
            'phone' => $this->faker->phoneNumber,
            'position' => $this->faker->jobTitle,
            'license_number' => $this->faker->numerify('LIC-#####'),
            'activation_key' => $this->faker->uuid,
            'url_link' => $this->faker->url,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
