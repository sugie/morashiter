<?php

namespace App\Services\Crawling;

use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlSource;

class UrlPolicy
{
    private const TRACKING_KEYS = ['fbclid', 'gclid'];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private readonly HostResolver $hostResolver)
    {
    }

    public function normalize(string $url): string
    {
        $parts = @parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new UrlPolicyViolation('malformed_url', $url, 'URL is malformed or missing scheme/host.');
        }

        $parts['scheme'] = strtolower($parts['scheme']);
        $parts['host'] = strtolower($parts['host']);
        unset($parts['fragment']);

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            $kept = [];
            foreach ($params as $key => $value) {
                if (str_starts_with((string) $key, 'utm_')) {
                    continue;
                }
                if (in_array($key, self::TRACKING_KEYS, true)) {
                    continue;
                }
                $kept[$key] = $value;
            }
            if ($kept === []) {
                unset($parts['query']);
            } else {
                $parts['query'] = http_build_query($kept);
            }
        }

        return $this->rebuild($parts);
    }

    public function validate(string $url, CrawlSource $source): string
    {
        $normalized = $this->normalize($url);
        $parts = parse_url($normalized);

        $allowedSchemes = (array) config('crawler.allowed_schemes', ['https']);
        if (! in_array($parts['scheme'], $allowedSchemes, true)) {
            throw new UrlPolicyViolation('scheme_denied', $url, "Scheme {$parts['scheme']} is not allowed.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UrlPolicyViolation('userinfo_present', $url, 'URLs with embedded user credentials are rejected.');
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$parts['scheme']] ?? null;
        $allowedPorts = (array) config('crawler.allowed_ports', [80, 443]);
        if ($port === null || ! in_array($port, $allowedPorts, true)) {
            throw new UrlPolicyViolation('port_denied', $url, "Port {$port} is not allowed.");
        }

        $allowedHosts = array_map('strtolower', $source->allowed_hosts ?? []);
        if (! in_array($parts['host'], $allowedHosts, true)) {
            throw new UrlPolicyViolation('host_not_allowed', $url, "Host {$parts['host']} is not in the source's allowed_hosts list.");
        }

        $ips = $this->hostResolver->resolve($parts['host']);
        if ($ips === []) {
            throw new UrlPolicyViolation('dns_failure', $url, "Host {$parts['host']} did not resolve to any address.");
        }
        $trustedPrivateHosts = array_map('strtolower', (array) config('crawler.trusted_private_hosts', []));
        $hostTrusted = in_array($parts['host'], $trustedPrivateHosts, true);
        if (! $hostTrusted) {
            foreach ($ips as $ip) {
                if ($this->isPrivateOrReserved($ip)) {
                    throw new UrlPolicyViolation('private_address', $url, "Host {$parts['host']} resolves to a private or reserved address: {$ip}.");
                }
            }
        }

        return $normalized;
    }

    private function rebuild(array $parts): string
    {
        $scheme = $parts['scheme'].'://';
        $user = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.$user.$host.$port.$path.$query;
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $expanded = $this->expandIpv6($ip);
            if (str_starts_with($expanded, '0000:0000:0000:0000:0000:ffff:')) {
                $packed = inet_pton($ip);
                if ($packed !== false && strlen($packed) === 16) {
                    $v4 = inet_ntop(substr($packed, 12));
                    if (is_string($v4)) {
                        return $this->isPrivateOrReserved($v4);
                    }
                }
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function expandIpv6(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        $hex = bin2hex($packed);

        return implode(':', str_split($hex, 4));
    }
}
