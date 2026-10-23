<?php

namespace Tests\Feature\Msnc;

use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OfficialDomainBudgetConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function budgets(): array
    {
        return ['last slot' => [9, 10], 'first row creation' => [null, 1]];
    }

    #[DataProvider('budgets')]
    public function test_concurrent_research_cannot_send_more_than_the_remaining_budget(?int $used, int $limit): void
    {
        config()->set('domain_research.enabled', false);
        $items = [];
        foreach (['alpha.example', 'beta.example'] as $host) {
            OfficialLinkCandidate::factory()->create(['host' => $host, 'status' => 'pending']);
            $items[] = OfficialDomainInvestigation::factory()->create([
                'host' => $host, 'status' => 'queued', 'recommendation' => 'hold', 'attempts' => 0,
            ]);
        }
        if ($used !== null) {
            DB::table('domain_research_budgets')->insert(['day' => '2026-10-23', 'used' => $used]);
        }
        DB::unprepared("
            CREATE FUNCTION fixture_budget_barrier() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF current_setting('application_name') = 'budget-first' THEN
                    PERFORM pg_advisory_xact_lock(2020, 1818);
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER fixture_budget_barrier BEFORE UPDATE ON domain_research_budgets
            FOR EACH ROW EXECUTE FUNCTION fixture_budget_barrier();
        ");
        $first = $this->worker($items[0], $limit, 'budget-first');
        $second = $this->worker($items[1], $limit, 'budget-second');
        config()->set('database.connections.budget_barrier', config('database.connections.pgsql'));
        $barrier = DB::connection('budget_barrier');
        try {
            $barrier->beginTransaction();
            $barrier->select('select pg_advisory_xact_lock(2020, 1818)');
            $pid = (int) $barrier->selectOne('select pg_backend_pid() as pid')->pid;

            $first->start();
            $firstPid = $this->waitForBlocker($first, 'budget-first', $pid);
            $second->start();
            $secondPid = $this->waitForBlocker($second, 'budget-second', $firstPid);
            $this->assertNotSame($firstPid, $secondPid);
            $barrier->commit();
            $first->wait();
            $second->wait();

            foreach ([$first, $second] as $process) {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $this->assertSame(['ai_requests' => 1], json_decode($first->getOutput(), true));
            $this->assertSame(['ai_requests' => 0], json_decode($second->getOutput(), true));
            $this->assertDatabaseHas('domain_research_budgets', ['day' => '2026-10-23', 'used' => $limit]);
            $this->assertSame('completed', $items[0]->fresh()->status);
            $this->assertSame(1, $items[0]->fresh()->attempts);
            $this->assertSame('queued', $items[1]->fresh()->status);
            $this->assertSame('daily_limit', $items[1]->fresh()->error_code);
            $this->assertSame(0, $items[1]->fresh()->attempts);
            $this->assertDatabaseCount('official_domains', 0);
        } finally {
            if ($barrier->transactionLevel() > 0) {
                $barrier->rollBack();
            }
            $first->stop(0);
            $second->stop(0);
            DB::purge('budget_barrier');
            DB::unprepared('DROP TRIGGER IF EXISTS fixture_budget_barrier ON domain_research_budgets; DROP FUNCTION IF EXISTS fixture_budget_barrier();');
        }
    }

    private function worker(OfficialDomainInvestigation $item, int $limit, string $label): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/run-official-domain-budget.php'), $label], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'mrst_test', 'DB_USERNAME' => 'mrst_app',
            'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_URL' => '',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setInput(json_encode(['id' => $item->id, 'host' => $item->host, 'limit' => $limit], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }

    private function waitForBlocker(Process $process, string $label, int $blockerPid): int
    {
        $deadline = microtime(true) + 15;
        do {
            $row = DB::selectOne(
                'select pid from pg_stat_activity where application_name = ? and ? = ANY(pg_blocking_pids(pid))',
                [$label, $blockerPid],
            );
            if ($row !== null) {
                return (int) $row->pid;
            }
            if (! $process->isRunning()) {
                $this->fail('Worker exited before overlap: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('The expected concurrent PostgreSQL lock wait was not observed.');
    }
}
