<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminIpAllowed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('infrastructure.admin_allowed_ips', []);

        abort_unless($allowedIps !== [] && IpUtils::checkIp($request->ip(), $allowedIps), 403);

        return $next($request);
    }
}
