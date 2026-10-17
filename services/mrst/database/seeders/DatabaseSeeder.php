<?php

namespace Database\Seeders;

use App\Models\CrawlSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (App::environment('local', 'testing')) {
            CrawlSource::query()->firstOrCreate(
                ['name' => 'Fixture News Local'],
                [
                    'base_url'          => 'http://fixture-news/feed.xml',
                    'source_type'       => 'news',
                    'crawler_code'      => 'msnc',
                    'allowed_hosts'     => ['fixture-news'],
                    'extraction_config' => ['adapter_code' => 'fixture_news'],
                    'is_enabled'        => true,
                ],
            );
        }
    }
}
