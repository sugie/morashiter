<?php

namespace Tests\Feature\Msgn;

use App\Models\AdminUser;
use App\Models\Incident;
use App\Models\IncidentRevision;
use App\Services\Publishing\PublicSnapshotBuilder;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicSnapshotBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(LaunchIncidentsSeeder::class)->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return json_decode(app(PublicSnapshotBuilder::class)->json(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function launchData(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/launch-incidents.json')), true);
    }

    public function test_the_seeded_incidents_produce_exactly_the_launch_data(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame('public-snapshot.v1', $snapshot['schema_version']);
        $this->assertSame($this->launchData(), $snapshot['incidents']);
        $this->assertSame([], $snapshot['withdrawals']);
        $this->assertSame('2026-09-29', $snapshot['data_as_of']);
    }

    public function test_snapshot_loads_incidents_and_revisions_without_a_query_per_article(): void
    {
        DB::enableQueryLog();

        $snapshot = $this->snapshot();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(5, $snapshot['incidents']);
        // Incidents and their revisions, plus one query for the shopping hosts (merchants).
        $this->assertLessThanOrEqual(4, count($queries));
    }

    public function test_only_public_fields_are_included(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame(['schema_version', 'data_as_of', 'incidents', 'withdrawals', 'merchants'], array_keys($snapshot));
        $this->assertSame(['id', 'name', 'company', 'type', 'scale', 'brief', 'date', 'status', 'disclosure', 'report', 'quantity', 'dataType', 'summary', 'target', 'action', 'response', 'source', 'contact'], array_keys($snapshot['incidents'][0]));
        $raw = app(PublicSnapshotBuilder::class)->json();
        foreach (['approved_by', 'incident_review', 'approval_method', 'private_object_key', 'password', 'analysis'] as $internal) {
            $this->assertStringNotContainsString($internal, $raw);
        }
    }

    public function test_output_is_deterministic(): void
    {
        $builder = app(PublicSnapshotBuilder::class);
        $first = $builder->json();
        Carbon::setTestNow(now()->addDay());
        $second = $builder->json();
        Carbon::setTestNow();

        $this->assertSame($first, $second);
        $this->assertSame(hash('sha256', $first), $builder->sha256());
    }

    public function test_the_latest_approved_revision_is_published(): void
    {
        $incident = Incident::query()->where('display_code', 'INC-002')->sole();
        $base = $incident->currentRevision->replicate();
        $base->fill(['revision_number' => 2, 'report' => '第2報', 'quantity' => '1,500件']);
        $base->save();
        $draft = $base->replicate();
        $draft->fill(['revision_number' => 3, 'status' => 'superseded_draft', 'quantity' => '9,999件']);
        $draft->save();

        $eplus = collect($this->snapshot()['incidents'])->firstWhere('id', 'INC-002');

        $this->assertSame('第2報', $eplus['report']);
        $this->assertSame('1,500件', $eplus['quantity']);
    }

    public function test_a_content_change_changes_the_hash(): void
    {
        $before = app(PublicSnapshotBuilder::class)->sha256();
        IncidentRevision::query()->whereHas('incident', fn ($q) => $q->where('display_code', 'INC-001'))->update(['summary' => '更新後の概要']);

        $this->assertNotSame($before, app(PublicSnapshotBuilder::class)->sha256());
    }

    public function test_withdrawn_incidents_leave_the_list_and_become_notices(): void
    {
        $admin = AdminUser::factory()->active()->create();
        Incident::query()->where('display_code', 'INC-003')->sole()->update([
            'withdrawn_at' => Carbon::parse('2026-10-20 23:30:00+09:00')->utc(), // app code writes now() in UTC
            'withdrawal_reason' => '公表内容の訂正のため',
            'withdrawn_by_admin_id' => $admin->id,
        ]);

        $snapshot = $this->snapshot();

        $this->assertSame(['INC-001', 'INC-002', 'INC-004', 'INC-005'], array_column($snapshot['incidents'], 'id'));
        $this->assertSame([[
            'id' => 'INC-003',
            'name' => 'ASKUL・LOHACO',
            'company' => 'アスクル株式会社',
            'date' => '2026/10/20',
            'reason' => '公表内容の訂正のため',
        ]], $snapshot['withdrawals']);
    }

    public function test_command_writes_the_snapshot_and_prints_its_hash(): void
    {
        $path = storage_path('framework/testing/snapshot-'.getmypid().'.json');

        $this->artisan('msgn:snapshot', ['--output' => $path])
            ->expectsOutputToContain(app(PublicSnapshotBuilder::class)->sha256())
            ->assertExitCode(0);

        $this->assertSame(app(PublicSnapshotBuilder::class)->json(), file_get_contents($path));
        unlink($path);
    }
}
