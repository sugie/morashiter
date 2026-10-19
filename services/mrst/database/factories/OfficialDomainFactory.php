<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OfficialDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialDomain>
 */
class OfficialDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'host' => 'www.corp'.fake()->unique()->numberBetween(1, 99999).'.example.co.jp',
            'verified_by_admin_id' => null,
            'verified_at' => now(),
            'note' => null,
        ];
    }
}
