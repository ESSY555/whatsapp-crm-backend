<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The platform administrator account that signs in at /admin/login. It has
 * no business membership — it exists only for the admin console.
 *
 * Credentials come from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD in .env so
 * they never live in the repository. Re-running keeps the account a super
 * admin and resets the password to the configured value.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('billing.super_admin.email');
        $password = (string) config('billing.super_admin.password');

        if ($email === '' || $password === '') {
            $this->command?->warn('SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD are not set; skipping super admin.');
            return;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?: (string) config('billing.super_admin.name', 'Crediping Admin'),
            'password' => Hash::make($password),
            'is_super_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->command?->info("Super admin ready: {$email}");
    }
}
