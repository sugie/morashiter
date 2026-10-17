<?php

namespace Database\Factories;

use App\Models\CrawlItem;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlItem>
 */
class CrawlItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = 'http://fixture-news/incidents/incident-'.fake()->unique()->numberBetween(1, 1_000_000).'.html';

        return [
            'source_id'      => CrawlSource::factory(),
            'crawl_run_id'   => fn (array $attributes): int => CrawlRun::factory()->create(['source_id' => $attributes['source_id']])->id,
            'normalized_url' => $url,
            'url_hash'       => hash('sha256', $url),
            'status'         => 'queued',
            'attempts'       => 0,
            'discovered_at'  => now(),
        ];
    }
}
