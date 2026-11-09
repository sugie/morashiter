<?php

namespace Tests\Feature\Msgn;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class PublishScheduleTest extends TestCase
{
    private function publishEvent(): Event
    {
        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'msgn:publish')));
        $this->assertCount(1, $events, 'Register exactly one daily publisher.');

        return $events[0];
    }

    public function test_daily_publish_is_due_only_at_0330_jst(): void
    {
        $event = $this->publishEvent();
        foreach (['2026-11-09 18:29:00' => false, '2026-11-09 18:30:00' => true,
            '2026-11-09 18:31:00' => false, '2026-11-10 03:30:00' => false,
            '2026-11-10 18:30:00' => true] as $utc => $due) {
            $this->travelTo(Carbon::parse($utc, 'UTC'));
            $this->assertSame($due, $event->isDue($this->app), $utc);
        }
    }

    public function test_automatic_publishing_requires_both_switches(): void
    {
        $event = $this->publishEvent();
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$enabled, $auto]) {
            config(['msgn.enabled' => $enabled, 'msgn.auto_publish' => $auto]);
            $this->assertSame($enabled && $auto, $event->filtersPass($this->app));
        }
    }

    public function test_publication_does_not_block_other_schedules_and_keeps_bounded_output(): void
    {
        $event = $this->publishEvent();
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(storage_path('logs/msgn-schedule.log'), $event->output);
        $this->assertFalse($event->shouldAppendOutput);
    }
}
