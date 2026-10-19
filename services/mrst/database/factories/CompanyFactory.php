<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'テスト株式会社'.fake()->unique()->numberBetween(1, 99999),
            'corporate_number' => null,
        ];
    }
}
