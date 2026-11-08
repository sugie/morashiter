<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MerchantDomain;
use App\Services\Crawling\RegistrableDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantDomain>
 */
class MerchantDomainFactory extends Factory
{
    public function definition(): array
    {
        $host = 'shop'.fake()->unique()->numberBetween(1, 99999).'.example.co.jp';

        return [
            'host' => $host,
            'registrable_domain' => fn (array $attributes): string => (string) RegistrableDomain::of($attributes['host']),
            'company_id' => Company::factory(),
            'evidence_url' => 'https://www.example.co.jp/company/shops.html',
            'note' => null,
            'verified_by_admin_id' => null,
            'verified_at' => now(),
            'disabled_at' => null,
        ];
    }
}
