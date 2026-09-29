<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
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
            'public_id' => (string) Str::ulid(),
            'name_ar' => 'شركة '.fake()->company(),
            'name_en' => fake()->company(),
            'legal_name_ar' => 'شركة '.fake()->company().' ذ.م.م',
            'legal_name_en' => fake()->company().' LLC',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
            'phone' => fake()->phoneNumber(),
            'whatsapp' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'website' => fake()->url(),
            'address_ar' => 'فلسطين',
            'address_en' => 'Palestine',
            'registration_number' => (string) fake()->numerify('########'),
            'tax_number' => (string) fake()->numerify('#########'),
            'status' => 'active',
        ];
    }
}
