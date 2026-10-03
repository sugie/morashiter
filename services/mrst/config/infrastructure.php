<?php

return [
    'trusted_proxies' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),
    'admin_allowed_ips' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('ADMIN_ALLOWED_IPS', '')),
    ))),
];
