<?php

namespace Tests\Feature\Admin;

use App\Jobs\Msgn\PublishSite;
use App\Models\AdminUser;
use App\Models\SiteRelease;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        Config::set('msgn.enabled', true);
        Queue::fake();
    }

    private function login(): void
    {
        $this->admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($this->admin);
    }

    public function test_page_requires_an_admin(): void
    {
        $this->get('/admin/publications')->assertRedirect(route('admin.login'));
    }

    public function test_page_lists_releases_newest_first(): void
    {
        SiteRelease::query()->create(['release_id' => 'aaaaaaaaaaaa', 'status' => 'superseded', 'incident_ids' => ['INC-001'], 'snapshot_sha256' => str_repeat('a', 64)]);
        SiteRelease::query()->create(['release_id' => 'bbbbbbbbbbbb', 'status' => 'live', 'incident_ids' => ['INC-001', 'INC-002'], 'snapshot_sha256' => str_repeat('b', 64)]);
        $this->login();

        $this->get('/admin/publications')->assertOk()->assertSeeInOrder(['bbbbbbbbbbbb', 'live', 'aaaaaaaaaaaa', 'superseded']);
    }

    public function test_publish_button_queues_a_publish_without_a_password(): void
    {
        $this->login();

        $this->post('/admin/publications')->assertRedirect('/admin/publications')->assertSessionHasNoErrors();

        Queue::assertPushedOn('msnc', PublishSite::class, fn (PublishSite $job): bool => $job->adminId === $this->admin->id);
    }

    public function test_publish_form_does_not_ask_for_the_password(): void
    {
        $this->login();

        $this->get('/admin/publications')->assertOk()->assertDontSee('name="password"', false);
    }

    public function test_publishing_is_refused_while_disabled(): void
    {
        Config::set('msgn.enabled', false);
        $this->login();

        $this->get('/admin/publications')->assertSee('MSGN_ENABLED');
        $this->post('/admin/publications')->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_inactive_admin_cannot_publish(): void
    {
        Auth::guard('admin')->login(AdminUser::factory()->create(['is_active' => false]));

        $this->post('/admin/publications')->assertForbidden();

        Queue::assertNothingPushed();
    }
}
