<?php

namespace Tests\Feature;

use App\Models\CrawlItem;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CrawlItemMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_duplicate_url_hash_within_a_run_is_rejected(): void
    {
        $existing = CrawlItem::factory()->create(['url_hash' => str_repeat('a', 64)]);

        $this->expectException(QueryException::class);

        CrawlItem::factory()->create([
            'source_id'    => $existing->source_id,
            'crawl_run_id' => $existing->crawl_run_id,
            'url_hash'     => $existing->url_hash,
        ]);
    }

    public function test_same_url_hash_may_appear_in_different_runs(): void
    {
        $source = CrawlSource::factory()->create();
        $hash = str_repeat('b', 64);
        $firstRun = CrawlRun::factory()->for($source, 'source')->create();
        $secondRun = CrawlRun::factory()->for($source, 'source')->create();

        CrawlItem::factory()->create([
            'source_id'    => $source->id,
            'crawl_run_id' => $firstRun->id,
            'url_hash'     => $hash,
        ]);
        $second = CrawlItem::factory()->create([
            'source_id'    => $source->id,
            'crawl_run_id' => $secondRun->id,
            'url_hash'     => $hash,
        ]);

        $this->assertNotNull($second->id);
        $this->assertSame(2, CrawlItem::query()->where('url_hash', $hash)->count());
    }

    public function test_insert_fails_when_crawl_run_does_not_exist(): void
    {
        $source = CrawlSource::factory()->create();

        $this->expectException(QueryException::class);

        CrawlItem::query()->create([
            'crawl_run_id'   => 99999999,
            'source_id'      => $source->id,
            'normalized_url' => 'http://fixture-news/incidents/orphan.html',
            'url_hash'       => str_repeat('c', 64),
            'status'         => 'queued',
            'attempts'       => 0,
            'discovered_at'  => now(),
        ]);
    }

    public function test_insert_fails_when_source_does_not_exist(): void
    {
        $run = CrawlRun::factory()->create();

        $this->expectException(QueryException::class);

        CrawlItem::query()->create([
            'crawl_run_id'   => $run->id,
            'source_id'      => 99999999,
            'normalized_url' => 'http://fixture-news/incidents/orphan.html',
            'url_hash'       => str_repeat('d', 64),
            'status'         => 'queued',
            'attempts'       => 0,
            'discovered_at'  => now(),
        ]);
    }
}
