<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_active_admin_without_storing_the_password_in_plaintext(): void
    {
        $this->artisan('admin:create', [
            'email' => 'ADMIN@example.test',
            '--name' => 'Operator',
            '--activate' => true,
        ])
            ->expectsQuestion('Password (at least 16 characters)', 'a-long-test-password')
            ->expectsQuestion('Confirm password', 'a-long-test-password')
            ->expectsOutput('Active administrator created.')
            ->assertExitCode(0);

        $admin = AdminUser::query()->sole();
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertSame('Operator', $admin->name);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('a-long-test-password', $admin->password));
    }

    public function test_command_creates_an_inactive_admin_by_default(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 16 characters)', 'a-long-test-password')
            ->expectsQuestion('Confirm password', 'a-long-test-password')
            ->assertExitCode(0);

        $this->assertFalse(AdminUser::query()->sole()->is_active);
    }

    public function test_command_rejects_duplicate_email_before_requesting_a_password(): void
    {
        AdminUser::factory()->create(['email' => 'admin@example.test']);

        $this->artisan('admin:create', ['email' => 'ADMIN@example.test'])
            ->expectsOutput('An administrator with this email address already exists.')
            ->assertExitCode(1);

        $this->assertSame(1, AdminUser::query()->count());
    }

    public function test_command_rejects_mismatched_passwords(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 16 characters)', 'a-long-test-password')
            ->expectsQuestion('Confirm password', 'another-long-password')
            ->expectsOutput('Passwords must match and contain at least 16 characters.')
            ->assertExitCode(1);

        $this->assertSame(0, AdminUser::query()->count());
    }
}
