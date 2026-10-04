<?php

namespace Database\Factories;

use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Models\SourceDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDocument>
 */
class SourceDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => CrawlSource::factory(),
            'crawl_run_id' => fn (array $attributes): int => CrawlRun::factory()->create(['source_id' => $attributes['source_id']])->id,
            'canonical_url' => 'https://news.example.test/incidents/sample',
            'final_url' => 'https://news.example.test/incidents/sample',
            'url_hash' => hash('sha256', 'https://news.example.test/incidents/sample'),
            'content_sha256' => hash('sha256', 'sample'),
            'title' => 'Fixture News',
            'published_on' => '2026-10-02',
            'fetched_at' => now(),
            'http_status' => 200,
            'content_type' => 'text/html',
            'private_object_key' => 'msnc/evidence/sample.json',
            'extraction_version' => 'fixture_news_v1',
        ];
    }
}
