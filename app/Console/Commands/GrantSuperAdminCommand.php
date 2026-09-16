<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to create the first super admin. Further admins can then be
 * granted from the admin UI.
 */
class GrantSuperAdminCommand extends Command
{
    protected $signature = 'admin:grant {email : Email of an existing user} {--revoke : Remove super admin access instead}';

    protected $description = 'Grant (or revoke) platform super admin access for a user';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (!$user) {
            $this->error("No user with email {$this->argument('email')}.");
            return self::FAILURE;
        }

        $grant = !$this->option('revoke');
        $user->forceFill(['is_super_admin' => $grant])->save();

        $this->info($grant ? "{$user->email} is now a super admin." : "Super admin access revoked for {$user->email}.");

        return self::SUCCESS;
    }
}
