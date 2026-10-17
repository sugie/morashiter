<?php

namespace Tests\Unit\Crawling;

use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlSource;
use App\Services\Crawling\UrlPolicy;
use Illuminate\Support\Facades\Config;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

class UrlPolicyTest extends TestCase
{
    private function source(array $allowedHosts): CrawlSource
    {
        $source = new CrawlSource();
        $source->allowed_hosts = $allowedHosts;

        return $source;
    }

    private function policy(array $hostMap = []): UrlPolicy
    {
        return new UrlPolicy(new InMemoryHostResolver($hostMap));
    }

    public function test_valid_https_url_is_normalized_and_returned(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        $normalized = $policy->validate(
            'https://news.example.test/incidents/1?utm_source=x&id=42&fbclid=y&gclid=z#frag',
            $source,
        );

        $this->assertSame('https://news.example.test/incidents/1?id=42', $normalized);
    }

    public function test_https_url_without_tracking_or_fragment_is_unchanged(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        $this->assertSame(
            'https://news.example.test/incidents/1',
            $policy->validate('https://news.example.test/incidents/1', $source),
        );
    }

    public function test_http_is_rejected_when_only_https_is_allowed(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        try {
            $policy->validate('http://news.example.test/x', $source);
            $this->fail('http URL should be rejected under https-only config.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('scheme_denied', $e->violationCode);
        }
    }

    public function test_http_is_accepted_when_both_schemes_allowed(): void
    {
        Config::set('crawler.allowed_schemes', ['http', 'https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        $this->assertSame(
            'http://news.example.test/x',
            $policy->validate('http://news.example.test/x', $source),
        );
    }

    public function test_trusted_private_host_bypasses_private_address_check(): void
    {
        Config::set('crawler.allowed_schemes', ['http']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        $source = $this->source(['fixture-news']);
        $policy = $this->policy(['fixture-news' => ['172.30.150.10']]);

        $this->assertSame(
            'http://fixture-news/incidents/incident-001.html',
            $policy->validate('http://fixture-news/incidents/incident-001.html', $source),
        );
    }

    public function test_trusted_private_host_still_requires_dns_resolution(): void
    {
        Config::set('crawler.allowed_schemes', ['http']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        $source = $this->source(['fixture-news']);
        $policy = $this->policy([]);

        try {
            $policy->validate('http://fixture-news/x', $source);
            $this->fail('Trusted-private host must still fail DNS resolution.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('dns_failure', $e->violationCode);
        }
    }

    public function test_untrusted_host_cannot_resolve_to_private_ip(): void
    {
        Config::set('crawler.allowed_schemes', ['http']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        $source = $this->source(['other.local.test']);
        $policy = $this->policy(['other.local.test' => ['10.0.0.1']]);

        try {
            $policy->validate('http://other.local.test/x', $source);
            $this->fail('Untrusted host should still be rejected when resolving to private IP.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('private_address', $e->violationCode);
        }
    }

    public function test_url_with_userinfo_is_rejected(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        try {
            $policy->validate('https://admin:secret@news.example.test/x', $source);
            $this->fail('URL with userinfo should be rejected.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('userinfo_present', $e->violationCode);
        }
    }

    public function test_disallowed_host_is_rejected(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['other.example.test' => ['93.184.216.34']]);

        try {
            $policy->validate('https://other.example.test/x', $source);
            $this->fail('Host outside allowed_hosts should be rejected.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('host_not_allowed', $e->violationCode);
        }
    }

    public function test_disallowed_port_is_rejected(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        Config::set('crawler.allowed_ports', [80, 443]);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        try {
            $policy->validate('https://news.example.test:8443/x', $source);
            $this->fail('Non-standard port should be rejected.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('port_denied', $e->violationCode);
        }
    }

    public static function privateIpProvider(): array
    {
        return [
            'rfc1918 10/8'      => ['10.0.0.1'],
            'rfc1918 192.168'   => ['192.168.1.1'],
            'rfc1918 172.16'    => ['172.16.0.1'],
            'loopback v4'       => ['127.0.0.1'],
            'link-local'        => ['169.254.169.254'],
            'reserved 0.0.0.0'  => ['0.0.0.1'],
            'loopback v6'       => ['::1'],
            'ipv4 mapped priv'  => ['::ffff:10.0.0.1'],
            'ipv4 mapped loop'  => ['::ffff:127.0.0.1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateIpProvider')]
    public function test_private_or_reserved_address_is_rejected(string $ip): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => [$ip]]);

        try {
            $policy->validate('https://news.example.test/x', $source);
            $this->fail("IP {$ip} should be rejected as private/reserved.");
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('private_address', $e->violationCode);
        }
    }

    public function test_unresolvable_host_is_rejected(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy([]);

        try {
            $policy->validate('https://news.example.test/x', $source);
            $this->fail('Unresolvable host should be rejected.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('dns_failure', $e->violationCode);
        }
    }

    public function test_mixed_resolution_rejects_when_any_ip_is_private(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34', '10.0.0.1']]);

        try {
            $policy->validate('https://news.example.test/x', $source);
            $this->fail('Host resolving to any private IP should be rejected.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('private_address', $e->violationCode);
        }
    }

    public function test_host_match_is_case_insensitive(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        $this->assertSame(
            'https://news.example.test/x',
            $policy->validate('https://News.Example.Test/x', $source),
        );
    }

    public function test_malformed_url_is_rejected(): void
    {
        Config::set('crawler.allowed_schemes', ['https']);
        $source = $this->source(['news.example.test']);
        $policy = $this->policy(['news.example.test' => ['93.184.216.34']]);

        try {
            $policy->validate('not-a-url', $source);
            $this->fail('Malformed URL should be rejected.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('malformed_url', $e->violationCode);
        }
    }
}
