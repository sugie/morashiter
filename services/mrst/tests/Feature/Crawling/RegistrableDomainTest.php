<?php

namespace Tests\Feature\Crawling;

use App\Services\Crawling\RegistrableDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrableDomainTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function hosts(): array
    {
        return [
            'com' => ['support.goodsmile.com', 'goodsmile.com'],
            'co.jp' => ['www.alpha-corp.co.jp', 'alpha-corp.co.jp'],
            'deep co.jp' => ['ir.news.alpha-corp.co.jp', 'alpha-corp.co.jp'],
            'go.jp' => ['www.mlit.go.jp', 'mlit.go.jp'],
            'prefecture under lg.jp' => ['www.pref.fukuoka.lg.jp', 'pref.fukuoka.lg.jp'],
            'city under lg.jp' => ['www.city.kamogawa.lg.jp', 'city.kamogawa.lg.jp'],
            'plain jp' => ['www.pref.okayama.jp', 'okayama.jp'],
            'upper case and trailing dot' => ['WWW.Example.COM.', 'example.com'],
            'a suffix alone' => ['co.jp', null],
            'an ip address' => ['192.168.0.1', null],
            'a single label' => ['localhost', null],
        ];
    }

    #[DataProvider('hosts')]
    public function test_registrable_domain(string $host, ?string $expected): void
    {
        $this->assertSame($expected, RegistrableDomain::of($host));
    }

    public function test_siblings_share_a_registrable_domain_and_neighbours_do_not(): void
    {
        $this->assertTrue(RegistrableDomain::same('support.goodsmile.com', 'www.goodsmile.com'));
        $this->assertFalse(RegistrableDomain::same('www.alpha-corp.co.jp', 'www.beta-corp.co.jp'));
        $this->assertFalse(RegistrableDomain::same('www.pref.fukuoka.lg.jp', 'www.city.fukuoka.lg.jp'));
        $this->assertFalse(RegistrableDomain::same('co.jp', 'co.jp'));
    }
}
