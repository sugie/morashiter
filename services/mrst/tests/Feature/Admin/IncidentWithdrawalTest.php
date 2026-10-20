<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Incident;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class IncidentWithdrawalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        app(LaunchIncidentsSeeder::class)->run();
    }

    private function login(bool $active = true): void
    {
        $this->admin = $active ? AdminUser::factory()->active()->create() : AdminUser::factory()->create(['is_active' => false]);
        Auth::guard('admin')->login($this->admin);
    }

    public function test_incident_list_shows_published_candidates(): void
    {
        $this->login();

        $this->get('/admin/incidents')
            ->assertOk()
            ->assertSeeInOrder(['INC-001', 'タイムズカー', 'INC-005']);
    }

    public function test_withdrawing_records_reason_admin_and_time(): void
    {
        $this->login();
        $incident = Incident::query()->where('display_code', 'INC-003')->sole();

        $this->post("/admin/incidents/{$incident->id}/withdraw", ['reason' => '公表内容の訂正のため', 'password' => 'password'])
            ->assertRedirect('/admin/incidents');

        $incident->refresh();
        $this->assertNotNull($incident->withdrawn_at);
        $this->assertSame('公表内容の訂正のため', $incident->withdrawal_reason);
        $this->assertSame($this->admin->id, $incident->withdrawn_by_admin_id);
    }

    public function test_withdrawal_needs_a_reason_and_the_password(): void
    {
        $this->login();
        $incident = Incident::query()->where('display_code', 'INC-003')->sole();

        $this->post("/admin/incidents/{$incident->id}/withdraw", ['reason' => '', 'password' => 'password'])->assertSessionHasErrors('reason');
        $this->post("/admin/incidents/{$incident->id}/withdraw", ['reason' => '訂正', 'password' => 'wrong'])->assertSessionHasErrors('password');

        $this->assertNull($incident->fresh()->withdrawn_at);
    }

    public function test_a_withdrawn_incident_cannot_be_withdrawn_again(): void
    {
        $this->login();
        $incident = Incident::query()->where('display_code', 'INC-003')->sole();
        $this->post("/admin/incidents/{$incident->id}/withdraw", ['reason' => '訂正', 'password' => 'password']);

        $this->post("/admin/incidents/{$incident->id}/withdraw", ['reason' => '再度', 'password' => 'password'])->assertStatus(409);
    }

    public function test_inactive_admin_cannot_withdraw(): void
    {
        $this->login(active: false);
        $incident = Incident::query()->where('display_code', 'INC-003')->sole();

        $this->post("/admin/incidents/{$incident->id}/withdraw", ['reason' => '訂正', 'password' => 'password'])->assertForbidden();

        $this->assertNull($incident->fresh()->withdrawn_at);
    }
}
