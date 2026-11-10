<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoShop
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('demo_shop.enabled') && $request->getHost() === config('demo_shop.host'), 404);
        abort_unless(config('demo_shop.paypal.mode') === 'sandbox', 503, 'Sandbox configuration required.');
        $cacheDriver = config('cache.stores.'.config('cache.default').'.driver');
        abort_if(! app()->environment('testing') && in_array($cacheDriver, ['array', 'null'], true), 503, 'Persistent demo cache required.');
        if (! $request->session()->has('demo_owner')) {
            $request->session()->put('demo_owner', (string) Str::uuid());
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
