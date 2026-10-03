<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_only_the_configured_proxy_can_supply_client_ip_and_scheme(): void
    {
        config()->set('infrastructure.trusted_proxies', ['10.0.0.0/8']);
        (new AppServiceProvider($this->app))->boot();
        $this->registerProbe();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.5.6.7'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.10',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'attacker.example',
            ])->get('http://api.morashiter.com/_test/proxy');

        $response->assertOk()->assertExactJson([
            'ip' => '203.0.113.10',
            'scheme' => 'https',
            'host' => 'api.morashiter.com',
        ]);
    }

    public function test_an_untrusted_client_cannot_spoof_proxy_headers(): void
    {
        config()->set('infrastructure.trusted_proxies', ['10.0.0.0/8']);
        (new AppServiceProvider($this->app))->boot();
        $this->registerProbe();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.10',
                'X-Forwarded-Proto' => 'https',
            ])->get('http://api.morashiter.com/_test/proxy');

        $response->assertOk()->assertExactJson([
            'ip' => '198.51.100.22',
            'scheme' => 'http',
            'host' => 'api.morashiter.com',
        ]);
    }

    private function registerProbe(): void
    {
        Route::get('/_test/proxy', static fn (Request $request): array => [
            'ip' => $request->ip(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
        ]);
    }
}
