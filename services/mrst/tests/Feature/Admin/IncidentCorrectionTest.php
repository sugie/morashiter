<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Incident;
use App\Models\IncidentRevision;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class IncidentCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AdminUser $admin;

    private Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        app(LaunchIncidentsSeeder::class)->run();
        $this->incident = Incident::query()->where('display_code', 'INC-002')->sole();
    }

    private function login(bool $active = true): void
    {
        $this->admin = $active ? AdminUser::factory()->active()->create() : AdminUser::factory()->create(['is_active' => false]);
        Auth::guard('admin')->login($this->admin);
    }

    /**
     * @return array<string, string>
     */
    private function payloadFromCurrent(array $overrides = []): array
    {
        $revision = $this->incident->currentRevision;
        $payload = ['password' => 'password', 'base_revision' => (string) $revision->revision_number];
        foreach (IncidentRevision::PUBLIC_FIELDS as $siteKey => $column) {
            $payload[$siteKey === 'dataType' ? 'data_type' : $siteKey] = (string) $revision->{$column};
        }

        return $overrides + $payload;
    }

    public function test_the_list_links_to_the_correction_form_prefilled_with_the_current_revision(): void
    {
        $this->login();
        $revision = $this->incident->currentRevision;

        $this->get('/admin/incidents')->assertOk()->assertSee(route('admin.incidents.correct', $this->incident), false);
        $this->get("/admin/incidents/{$this->incident->id}/correct")
            ->assertOk()
            ->assertSee('訂正版の作成 INC-002')
            ->assertSee('第2版')
            ->assertSee($revision->source)
            ->assertSee('name="base_revision" value="1"', false)
            ->assertSee('value="'.e($revision->company).'"', false);
    }

    public function test_correcting_adds_a_new_revision_and_keeps_the_old_one(): void
    {
        $this->login();
        $before = $this->incident->currentRevision;

        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent(['company' => '訂正後の株式会社']))
            ->assertRedirect('/admin/incidents')
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'INC-002 の第2版（訂正）'));

        $current = $this->incident->fresh()->currentRevision;
        $this->assertSame(2, $current->revision_number);
        $this->assertSame('訂正後の株式会社', $current->company);
        $this->assertSame('correction', $current->approval_method);
        $this->assertSame($this->admin->id, $current->approved_by_admin_id);
        $this->assertNull($current->incident_review_id);
        $this->assertSame($before->summary, $current->summary);
        $this->assertSame($before->company, $before->fresh()->company, 'The earlier revision must stay unchanged.');
        $this->assertSame('訂正後の株式会社', $current->toPublicArray()['company']);
    }

    public function test_a_form_opened_before_a_newer_revision_is_rejected(): void
    {
        $this->login();
        $staleForm = $this->payloadFromCurrent(['company' => '古いフォームからの訂正']);
        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent(['company' => '先に保存した訂正']))
            ->assertRedirect('/admin/incidents');

        $this->post("/admin/incidents/{$this->incident->id}/correct", $staleForm)->assertSessionHasErrors('base_revision');

        $current = $this->incident->fresh()->currentRevision;
        $this->assertSame(2, $current->revision_number);
        $this->assertSame('先に保存した訂正', $current->company);
    }

    public function test_correction_needs_the_password_and_valid_fields(): void
    {
        $this->login();

        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent(['password' => 'wrong']))->assertSessionHasErrors('password');
        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent(['company' => '']))->assertSessionHasErrors('company');
        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent(['source' => 'http://example.com/']))->assertSessionHasErrors('source');

        $this->assertSame(1, IncidentRevision::query()->where('incident_id', $this->incident->id)->count());
    }

    public function test_a_withdrawn_incident_cannot_be_corrected(): void
    {
        $this->login();
        $this->incident->update(['withdrawn_at' => now(), 'withdrawal_reason' => '訂正']);

        $this->get("/admin/incidents/{$this->incident->id}/correct")->assertStatus(409);
        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent())->assertStatus(409);

        $this->assertSame(1, IncidentRevision::query()->where('incident_id', $this->incident->id)->count());
    }

    public function test_inactive_admin_cannot_correct(): void
    {
        $this->login(active: false);

        $this->post("/admin/incidents/{$this->incident->id}/correct", $this->payloadFromCurrent())->assertForbidden();

        $this->assertSame(1, IncidentRevision::query()->where('incident_id', $this->incident->id)->count());
    }
}
