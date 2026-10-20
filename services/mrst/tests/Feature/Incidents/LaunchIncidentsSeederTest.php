<?php

namespace Tests\Feature\Incidents;

use App\Models\Incident;
use App\Models\IncidentRevision;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LaunchIncidentsSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_five_human_checked_incidents_become_approved_revisions(): void
    {
        app(LaunchIncidentsSeeder::class)->run();

        $this->assertSame(['INC-001', 'INC-002', 'INC-003', 'INC-004', 'INC-005'], Incident::query()->orderBy('display_code')->pluck('display_code')->all());
        $this->assertSame(5, IncidentRevision::query()->where('status', 'approved')->count());

        $eplus = Incident::query()->where('display_code', 'INC-002')->sole()->currentRevision;
        $this->assertSame('イープラス', $eplus->name);
        $this->assertSame('株式会社イープラス', $eplus->company);
        $this->assertSame('1,463件', $eplus->quantity);
        $this->assertSame('2026/09/29', $eplus->date);
        $this->assertNull($eplus->report);
        $this->assertSame('https://support-qa.eplus.jp/hc/ja/articles/62711500727961', $eplus->source);
        $this->assertSame('manual', $eplus->approval_method);
        $this->assertSame('seed', $eplus->incident->origin);
    }

    public function test_seeded_revisions_reproduce_the_published_data_exactly(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        $expected = json_decode(file_get_contents(database_path('seeders/data/launch-incidents.json')), true);

        foreach ($expected as $row) {
            $revision = Incident::query()->where('display_code', $row['id'])->sole()->currentRevision;
            $this->assertSame($row, $revision->toPublicArray(), $row['id']);
        }
    }

    public function test_seeding_twice_does_not_duplicate(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        app(LaunchIncidentsSeeder::class)->run();

        $this->assertSame(5, Incident::query()->count());
        $this->assertSame(5, IncidentRevision::query()->count());
    }
}
