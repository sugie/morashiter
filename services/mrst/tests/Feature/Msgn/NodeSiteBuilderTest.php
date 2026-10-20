<?php

namespace Tests\Feature\Msgn;

use App\Exceptions\SiteBuildFailure;
use App\Services\Publishing\NodeSiteBuilder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class NodeSiteBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('msgn.site_root', '/opt/site');
    }

    public function test_build_and_verify_run_with_fixed_arguments_and_no_secrets(): void
    {
        Process::fake([
            '*build-static.mjs*' => Process::result('noise'."\n".json_encode(['ok' => true, 'releaseId' => 'abc123def456', 'incidentIds' => ['INC-001'], 'dataAsOf' => '2026-09-29', 'indexable' => false])),
            '*verify-static.mjs*' => Process::result('OK'),
        ]);
        $_ENV['MSGN_S3_SECRET'] = 'must-not-leak';

        $release = (new NodeSiteBuilder)->build('/work/1/snapshot.json', '/work/1/previous.json', '/work/1/dist', false);

        $this->assertSame('abc123def456', $release['releaseId']);
        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command === [...NodeSiteBuilder::CLEAN_ENV, 'node', 'scripts/build-static.mjs', '--snapshot=/work/1/snapshot.json', '--out=/work/1/dist', '--previous-release=/work/1/previous.json']
                && $process->path === '/opt/site';
        });
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...NodeSiteBuilder::CLEAN_ENV, 'node', 'scripts/verify-static.mjs', '/work/1/dist', '--snapshot=/work/1/snapshot.json']);
        // env -i starts Node with an empty environment plus only these three variables.
        $this->assertSame(['env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'HOME=/tmp', 'NODE_ENV=production'], NodeSiteBuilder::CLEAN_ENV);
        Process::assertRan(fn (PendingProcess $process): bool => ! str_contains(json_encode([$process->command, $process->environment]), 'must-not-leak'));
        unset($_ENV['MSGN_S3_SECRET']);
    }

    public function test_indexable_builds_pass_the_flag_to_both_steps(): void
    {
        Process::fake([
            '*build-static.mjs*' => Process::result(json_encode(['ok' => true, 'releaseId' => 'r', 'incidentIds' => [], 'dataAsOf' => null, 'indexable' => true])),
            '*verify-static.mjs*' => Process::result('OK'),
        ]);

        (new NodeSiteBuilder)->build('/w/s.json', null, '/w/dist', true);

        Process::assertRan(fn (PendingProcess $p): bool => $p->command === [...NodeSiteBuilder::CLEAN_ENV, 'node', 'scripts/build-static.mjs', '--snapshot=/w/s.json', '--out=/w/dist', '--indexable']);
        Process::assertRan(fn (PendingProcess $p): bool => $p->command === [...NodeSiteBuilder::CLEAN_ENV, 'node', 'scripts/verify-static.mjs', '/w/dist', '--snapshot=/w/s.json', '--indexable']);
    }

    public function test_a_refused_build_raises_without_running_verification(): void
    {
        Process::fake(['*build-static.mjs*' => Process::result('', 'build refused: INC-003 would disappear', 1)]);

        try {
            (new NodeSiteBuilder)->build('/w/s.json', null, '/w/dist', false);
            $this->fail('Expected SiteBuildFailure');
        } catch (SiteBuildFailure $e) {
            $this->assertStringContainsString('INC-003 would disappear', $e->getMessage());
        }
        Process::assertDidntRun(fn (PendingProcess $p): bool => in_array('scripts/verify-static.mjs', $p->command, true));
    }

    public function test_a_failed_verification_raises(): void
    {
        Process::fake([
            '*build-static.mjs*' => Process::result(json_encode(['ok' => true, 'releaseId' => 'r', 'incidentIds' => [], 'dataAsOf' => null, 'indexable' => false])),
            '*verify-static.mjs*' => Process::result('', 'NG index.html: unexpected incident code INC-999', 1),
        ]);

        $this->expectException(SiteBuildFailure::class);
        $this->expectExceptionMessage('INC-999');
        (new NodeSiteBuilder)->build('/w/s.json', null, '/w/dist', false);
    }
}
