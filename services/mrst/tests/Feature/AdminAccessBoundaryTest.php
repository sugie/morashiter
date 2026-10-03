<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminIpAllowed;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminAccessBoundaryTest extends TestCase
{
    public function test_admin_path_is_denied_when_no_allowed_ip_is_configured(): void
    {
        Route::middleware(EnsureAdminIpAllowed::class)->get('/admin/_probe', fn (): string => 'allowed');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/admin/_probe')
            ->assertForbidden();
    }

    public function test_admin_path_accepts_the_allowed_client_behind_the_configured_lb(): void
    {
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        TrustProxies::at(['10.0.0.0/8']);
        Route::middleware(EnsureAdminIpAllowed::class)->get('/admin/_probe', fn (): string => 'allowed');

        $this->withServerVariables(['REMOTE_ADDR' => '10.5.6.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
            ->get('/admin/_probe')
            ->assertOk()
            ->assertSeeText('allowed');
    }

    public function test_untrusted_client_cannot_spoof_an_allowed_ip(): void
    {
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        TrustProxies::at(['10.0.0.0/8']);
        Route::middleware(EnsureAdminIpAllowed::class)->get('/admin/_probe', fn (): string => 'allowed');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
            ->get('/admin/_probe')
            ->assertForbidden();
    }
}
