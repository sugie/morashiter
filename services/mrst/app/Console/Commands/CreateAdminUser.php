<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admin:create {email} {--name=} {--activate}')]
#[Description('Create an administrator with an interactively entered password')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $email));

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '' || mb_strlen($name) > 255) {
            $this->error('Enter a valid email address and a display name of at most 255 characters.');

            return self::FAILURE;
        }

        if (AdminUser::query()->where('email', $email)->exists()) {
            $this->error('An administrator with this email address already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 16 characters)', fallback: false);
        $confirmation = $this->secret('Confirm password', fallback: false);

        if (! is_string($password) || strlen($password) < 16 || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('Passwords must match and contain at least 16 characters.');

            return self::FAILURE;
        }

        $admin = new AdminUser;
        $admin->name = $name;
        $admin->email = $email;
        $admin->password = $password;
        $admin->is_active = (bool) $this->option('activate');
        $admin->save();

        $this->info($admin->is_active ? 'Active administrator created.' : 'Inactive administrator created.');

        return self::SUCCESS;
    }
}
