<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoShopPageTest extends TestCase
{
    public function test_store_is_closed_by_default(): void
    {
        $this->get('/demo-shop')->assertNotFound();
    }

    public function test_enabled_store_shows_product_and_fictional_notices_without_ai_request(): void
    {
        Http::preventStrayRequests();
        config()->set('demo_shop.enabled', true);
        $this->get('http://aozora-zakka.localhost/demo-shop')->assertOk()
            ->assertSee('Aozora Goods')->assertSee('24.00')->assertSee('NO REAL CHARGES')
            ->assertSee('Create AI purchase memo')->assertSee('Save for later');
        $this->get('http://aozora-zakka.localhost/demo-shop/notices/DEMO-NOTICE-001')
            ->assertOk()->assertSee('Fictional')->assertSee('2026-08-05');
        $this->get('http://aozora-zakka.localhost/demo-shop/notices/MISSING')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_demo_rejects_other_hosts_and_live_mode(): void
    {
        config()->set('demo_shop.enabled', true);
        $this->get('http://www.morashiter.com/demo-shop')->assertNotFound();
        config()->set('demo_shop.paypal.mode', 'live');
        $this->get('http://aozora-zakka.localhost/demo-shop')->assertStatus(503);
    }

    public function test_ephemeral_cache_is_rejected_outside_tests(): void
    {
        config()->set(['demo_shop.enabled' => true, 'cache.default' => 'array']);
        $this->app->instance('env', 'local');
        $this->get('http://aozora-zakka.localhost/demo-shop')->assertStatus(503);
    }
}
