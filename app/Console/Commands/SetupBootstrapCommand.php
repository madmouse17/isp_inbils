<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SetupBootstrapCommand extends Command
{
    protected $signature = 'inbils:setup';

    protected $description = 'Create the first bootstrap user for Setup Wizard.';

    public function handle(): int
    {
        if (User::query()->count() > 0) {
            $this->info('Already bootstrapped.');

            return self::SUCCESS;
        }

        $attributes = [
            'name' => (string) $this->ask('Name'),
            'email' => (string) $this->ask('Email'),
            'password' => Hash::make((string) $this->secret('Password')),
            'email_verified_at' => now(),
            'company_id' => null,
        ];

        if (Schema::hasColumn('users', 'is_active')) {
            $attributes['is_active'] = true;
        }

        User::query()->forceCreate($attributes);

        $this->info('Bootstrap user created. Login at /login → complete Setup Wizard.');

        return self::SUCCESS;
    }
}
