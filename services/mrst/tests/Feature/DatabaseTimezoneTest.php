<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laravel writes timestamps without an offset, so the database session must use the app timezone.
 * In production PgBouncer re-applies cached session parameters, so the connection itself must declare it.
 */
class DatabaseTimezoneTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pgsql_connection_declares_the_app_timezone(): void
    {
        $this->assertSame(config('app.timezone'), config('database.connections.pgsql.timezone'));
    }

    public function test_session_timezone_matches_the_app_after_reconnecting(): void
    {
        DB::purge('pgsql');

        $this->assertSame(config('app.timezone'), DB::selectOne('show timezone')->TimeZone);
    }

    public function test_session_timezone_is_restored_even_if_the_server_default_differs(): void
    {
        DB::statement("set time zone 'Asia/Tokyo'");
        DB::purge('pgsql');

        $this->assertSame(config('app.timezone'), DB::selectOne('show timezone')->TimeZone);
    }

    public function test_timestamptz_values_round_trip_as_the_same_instant(): void
    {
        // Application code writes now(), i.e. a Carbon in the app timezone.
        $instant = Carbon::parse('2026-10-20T05:00:01+09:00')->setTimezone(config('app.timezone'));
        DB::statement('create temporary table tz_probe (at timestamptz)');

        DB::table('tz_probe')->insert(['at' => $instant]);

        $storedUtc = DB::selectOne("select to_char(at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') as t from tz_probe")->t;
        $this->assertSame('2026-10-19T20:00:01', $storedUtc);
    }
}
