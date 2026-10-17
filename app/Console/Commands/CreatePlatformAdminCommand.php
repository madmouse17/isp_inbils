<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'inbils:platform:admin-create';

    protected $description = 'Create an operator admin for the /platform panel (interactive). Run it only when the operator is ready to enrol two-factor authentication at once.';

    public function handle(PlatformAuditService $audit): int
    {
        $input = [
            'name' => (string) $this->ask('Name'),
            'email' => (string) $this->ask('Email'),
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('platform_admins', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->create($validator->safe()->only(['name', 'email', 'password']));

        $audit->platform('admin.created', ['platform_admin_id' => $admin->id, 'source' => 'console'], $admin);

        $this->info("Platform admin {$admin->email} created. Sign in at /platform/login; two-factor enrolment is required at the first login, so do it now.");

        return self::SUCCESS;
    }
}
