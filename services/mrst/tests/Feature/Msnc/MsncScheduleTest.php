<?php

namespace Tests\Feature\Msnc;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MsncScheduleTest extends TestCase
{
    private function discoverEvents(): array
    {
        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'msnc:discover --all'),
        ));
    }

    public function test_feed_discovery_is_scheduled_once_an_hour(): void
    {
        $events = $this->discoverEvents();

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
    }

    public function test_feed_discovery_runs_never_overlap(): void
    {
        $this->assertTrue($this->discoverEvents()[0]->withoutOverlapping);
    }
}
