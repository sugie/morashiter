<?php

namespace Database\Factories;

use App\Models\CrawlRun;
use App\Models\CrawlSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlRun>
 */
class CrawlRunFactory extends Factory
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
            'job_key' => fake()->unique()->uuid(),
            'status' => 'queued',
        ];
    }
}
