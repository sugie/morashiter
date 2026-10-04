<?php

namespace Database\Factories;

use App\Models\CrawlSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlSource>
 */
class CrawlSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Fixture News',
            'base_url' => 'https://news.example.test/incidents/sample',
            'source_type' => 'news',
            'crawler_code' => 'msnc',
            'allowed_hosts' => ['news.example.test'],
            'extraction_config' => ['adapter_code' => 'fixture_news'],
            'is_enabled' => true,
        ];
    }
}
