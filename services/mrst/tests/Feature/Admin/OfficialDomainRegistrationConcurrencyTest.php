<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\OfficialDomainResearchFixtures as F;
use Tests\TestCase;

class OfficialDomainRegistrationConcurrencyTest extends TestCase
{
    // Independent connections must see committed fixtures, so no outer test transaction.
    use DatabaseMigrations;

    public function test_two_admins_registering_the_same_host_create_one_complete_registration(): void
    {
        [$research, $firstAdmin, $secondAdmin] = $this->fixtures();
        $results = $this->registerConcurrently($research, $firstAdmin, $secondAdmin);

        $this->assertSame(['status' => 'registered'], $results[0]);
        $this->assertSame(['status' => 'rejected', 'http_status' => 409], $results[1]);
        $this->assertCompleteRegistration($research, $firstAdmin, '株式会社アルファ第一確認');
        $this->assertDatabaseMissing('companies', ['name' => '株式会社アルファ第二確認']);
    }

    public function test_failed_first_registration_rolls_back_and_waiting_admin_can_register(): void
    {
        [$research, $firstAdmin, $secondAdmin] = $this->fixtures();
        $results = $this->registerConcurrently($research, $firstAdmin, $secondAdmin, true);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('23514', $results[0]['code']);
        $this->assertSame(['status' => 'registered'], $results[1]);
        $this->assertCompleteRegistration($research, $secondAdmin, '株式会社アルファ第二確認');
        $this->assertDatabaseMissing('companies', ['name' => '株式会社アルファ第一確認']);
        $this->assertDatabaseMissing('official_domain_decisions', ['admin_id' => $firstAdmin->id]);
    }

    private function fixtures(): array
    {
        config()->set('domain_research.enabled', false);
        $first = AdminUser::factory()->active()->create(['name' => '確認担当A', 'email' => 'reviewer-a@example.test']);
        $second = AdminUser::factory()->active()->create(['name' => '確認担当B', 'email' => 'reviewer-b@example.test']);
        foreach ([['pending', F::HOST], ['pending', F::HOST], ['rejected', F::HOST], ['pending', 'beta.example']] as $index => [$status, $host]) {
            OfficialLinkCandidate::factory()->create([
                'host' => $host, 'status' => $status, 'url' => 'https://'.$host.'/news/'.$index,
                'url_hash' => hash('sha256', 'https://'.$host.'/news/'.$index),
            ]);
        }
        $research = OfficialDomainInvestigation::factory()->create([
            'host' => F::HOST, 'status' => 'completed', 'recommendation' => 'recommend',
            'organization_name' => '株式会社アルファ', 'evidence' => F::proposal()['evidence'],
            'raw_proposal' => F::proposal(), 'final_url' => F::URL,
        ]);

        return [$research, $first, $second];
    }

    private function worker(OfficialDomainInvestigation $research, AdminUser $admin, string $label, string $name): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/run-official-domain-registration.php'), $label], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'mrst_test', 'DB_USERNAME' => 'mrst_app',
            'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_URL' => '',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setInput(json_encode([
            'admin_id' => $admin->id,
            'data' => [
                'host' => F::HOST, 'investigation_id' => $research->id, 'decision' => 'register',
                'company_name' => $name, 'reason' => '会社概要と運営者を確認済み',
                'evidence_checked' => '1', 'ownership_checked' => '1',
                'name_checked' => '1', 'batch_checked' => '1',
            ],
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }

    private function registerConcurrently(OfficialDomainInvestigation $research, AdminUser $firstAdmin, AdminUser $secondAdmin, bool $failFirst = false): array
    {
        $failure = $failFirst ? "RAISE EXCEPTION 'fixture audit failure' USING ERRCODE = '23514';" : '';
        $firstId = (int) $firstAdmin->id;
        DB::unprepared("
            CREATE FUNCTION fixture_registration_barrier() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.admin_id = {$firstId} THEN
                    PERFORM pg_advisory_xact_lock(2020, 4242);
                    {$failure}
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER fixture_registration_barrier BEFORE INSERT ON official_domain_decisions
            FOR EACH ROW EXECUTE FUNCTION fixture_registration_barrier();
        ");
        $first = $this->worker($research, $firstAdmin, 'registration-first', '株式会社アルファ第一確認');
        $second = $this->worker($research, $secondAdmin, 'registration-second', '株式会社アルファ第二確認');
        config()->set('database.connections.registration_barrier', config('database.connections.pgsql'));
        $barrier = DB::connection('registration_barrier');
        try {
            $barrier->beginTransaction();
            $barrier->select('select pg_advisory_xact_lock(2020, 4242)');
            $barrierPid = (int) $barrier->selectOne('select pg_backend_pid() as pid')->pid;

            $first->start();
            $firstPid = $this->waitForBlocker($first, 'registration-first', $barrierPid);
            $second->start();
            $secondPid = $this->waitForBlocker($second, 'registration-second', $firstPid);
            $this->assertNotSame($firstPid, $secondPid, 'Registration must use independent PostgreSQL sessions.');
            $this->assertDatabaseCount('official_domains', 0);
            $this->assertDatabaseCount('companies', 0);
            $this->assertDatabaseCount('official_domain_decisions', 0);
            $this->assertSame(2, OfficialLinkCandidate::query()->where('host', F::HOST)->where('status', 'pending')->count());

            $barrier->commit();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());

            return [
                json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR),
                json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR),
            ];
        } finally {
            if ($barrier->transactionLevel() > 0) {
                $barrier->rollBack();
            }
            $first->stop(0);
            $second->stop(0);
            DB::purge('registration_barrier');
            DB::unprepared('DROP TRIGGER IF EXISTS fixture_registration_barrier ON official_domain_decisions; DROP FUNCTION IF EXISTS fixture_registration_barrier();');
        }
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
            // Poll observed DB synchronization; elapsed time alone never proves overlap.
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('The expected concurrent PostgreSQL lock wait was not observed.');
    }

    private function assertCompleteRegistration(OfficialDomainInvestigation $research, AdminUser $winner, string $name): void
    {
        $this->assertDatabaseCount('official_domains', 1);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('official_domain_decisions', 1);
        $company = DB::table('companies')->sole();
        $this->assertSame($name, $company->name);
        $this->assertDatabaseHas('official_domains', ['host' => F::HOST, 'company_id' => $company->id, 'verified_by_admin_id' => $winner->id]);
        $this->assertDatabaseHas('official_domain_decisions', [
            'investigation_id' => $research->id, 'host' => F::HOST, 'company_id' => $company->id,
            'admin_id' => $winner->id, 'company_name' => $name, 'decision' => 'register',
            'reason' => '会社概要と運営者を確認済み',
        ]);
        $this->assertSame(2, OfficialLinkCandidate::query()->where('host', F::HOST)->where('status', 'accepted')->count());
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'rejected']);
        $this->assertDatabaseHas('official_link_candidates', ['host' => 'beta.example', 'status' => 'pending']);
        $expected = F::proposal();
        $actual = $research->fresh()->raw_proposal;
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }
}
