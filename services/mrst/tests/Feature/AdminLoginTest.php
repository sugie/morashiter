<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
    }

    public function test_login_and_dashboard_are_blocked_outside_the_allowed_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->get('/admin/login')->assertForbidden();

        $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'secret'])
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->get('/admin')->assertForbidden();
    }

    public function test_guest_can_see_login_but_not_the_dashboard(): void
    {
        $this->get('/admin/login')->assertOk()->assertSeeText('Morashiter 管理画面');
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_active_admin_can_login_and_logout(): void
    {
        $admin = AdminUser::factory()->active()->create([
            'email' => 'admin@example.test',
            'password' => 'correct-password',
        ]);

        $this->post('/admin/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get('/admin')->assertOk()->assertSeeText('運用機能は今後追加します。');

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_inactive_admin_and_wrong_password_are_rejected(): void
    {
        AdminUser::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ]);

        $this->post('/admin/login', [
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->post('/admin/login', [
            'email' => 'inactive@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_regular_web_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_deactivated_admin_is_denied_even_with_an_existing_session(): void
    {
        $admin = AdminUser::factory()->active()->create([
            'email' => 'admin@example.test',
            'password' => 'correct-password',
        ]);

        $this->post('/admin/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));
        $this->get('/admin')->assertOk();

        AdminUser::query()->whereKey($admin->getKey())->update(['is_active' => false]);
        Auth::guard('admin')->forgetUser();

        $this->get('/admin')->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/admin/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }
}
