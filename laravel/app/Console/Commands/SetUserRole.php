<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'inventory:user-role {email} {role : admin or viewer}';

    protected $description = 'Set a portal user role (admin or viewer)';

    public function handle(): int
    {
        $role = (string) $this->argument('role');
        if (! in_array($role, User::ROLES, true)) {
            $this->error('role must be admin or viewer.');
            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('User not found.');
            return self::FAILURE;
        }

        $from = $user->role;
        $user->forceFill(['role' => $role])->save();
        AuditLog::record('user.role_changed', $user->email, ['from' => $from, 'to' => $role], 'cli');
        $this->info("{$user->email} is now {$role}.");

        return self::SUCCESS;
    }
}
