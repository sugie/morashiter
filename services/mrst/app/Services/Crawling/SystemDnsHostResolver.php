<?php

namespace App\Services\Crawling;

class SystemDnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        $a = @dns_get_record($host, DNS_A) ?: [];
        foreach ($a as $record) {
            if (! empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
