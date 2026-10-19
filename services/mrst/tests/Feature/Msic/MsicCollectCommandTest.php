<?php

namespace Tests\Feature\Msic;

use App\Jobs\Msic\FetchOfficialSource;
use App\Models\OfficialLinkCandidate;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MsicCollectCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.official_fetch_enabled', true);
        Queue::fake();
    }

    public function test_queues_one_job_per_accepted_candidate_only(): void
    {
        $accepted = OfficialLinkCandidate::factory()->count(2)->create(['status' => 'accepted']);
        foreach (['pending', 'rejected', 'fetched', 'failed'] as $status) {
            OfficialLinkCandidate::factory()->create(['status' => $status]);
        }

        $this->artisan('msic:collect')->assertExitCode(0);

        Queue::assertPushed(FetchOfficialSource::class, 2);
        foreach ($accepted as $candidate) {
            Queue::assertPushed(FetchOfficialSource::class, fn (FetchOfficialSource $job): bool => $job->candidateId === $candidate->id);
        }
        Queue::assertPushedOn('msnc', FetchOfficialSource::class);
    }

    public function test_batch_size_is_limited(): void
    {
        Config::set('crawler.official_batch_size', 3);
        OfficialLinkCandidate::factory()->count(5)->create(['status' => 'accepted']);

        $this->artisan('msic:collect')->assertExitCode(0);

        Queue::assertPushed(FetchOfficialSource::class, 3);
    }

    public function test_nothing_is_queued_while_disabled(): void
    {
        Config::set('crawler.official_fetch_enabled', false);
        OfficialLinkCandidate::factory()->create(['status' => 'accepted']);

        $this->artisan('msic:collect')
            ->expectsOutputToContain('msic fetching is closed: fetch_disabled')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_official_fetching_defaults_to_off_when_env_is_unset(): void
    {
        $previous = getenv('MSIC_FETCH_ENABLED');
        putenv('MSIC_FETCH_ENABLED');
        unset($_ENV['MSIC_FETCH_ENABLED'], $_SERVER['MSIC_FETCH_ENABLED']);

        try {
            $config = require base_path('config/crawler.php');
            $this->assertFalse($config['official_fetch_enabled']);
        } finally {
            if ($previous !== false) {
                putenv("MSIC_FETCH_ENABLED={$previous}");
                $_ENV['MSIC_FETCH_ENABLED'] = $_SERVER['MSIC_FETCH_ENABLED'] = $previous;
            }
        }
    }

    public function test_collection_is_scheduled_hourly_at_twenty_past(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'msic:collect'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('20 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}
