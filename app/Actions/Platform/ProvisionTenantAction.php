<?php

namespace App\Actions\Platform;

use App\Jobs\Platform\SeedTenantDemoDataJob;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Services\Core\SettingService;
use App\Services\Core\SetupWizardService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provisions a tenant from the platform panel: the company and its first admin through the same service as the
 * setup wizard, in one transaction. The admin gets a random 32-character password nobody knows and verified e-mail;
 * the invitation mail to set a password and, when asked, the sample-data job are queued once the transaction has
 * committed, so a failure anywhere leaves no company, no user and no mail.
 */
class ProvisionTenantAction
{
    public function __construct(
        private readonly SetupWizardService $wizard,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  what StoreTenantRequest validated
     */
    public function execute(PlatformAdmin $actor, array $validated): Company
    {
        $demoData = (bool) ($validated['demo_data'] ?? false);

        [$company, $admin] = DB::transaction(function () use ($actor, $validated, $demoData): array {
            $admin = new User([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Str::password(32),
                'is_active' => true,
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();

            $company = $this->wizard->createFor($admin, [
                ...Arr::only($validated, ['name', 'code', 'timezone', 'currency', 'logo', 'admin_name']),
                'date_format' => SettingService::get('default_date_format', 'd M Y'),
                'datetime_format' => 'd M Y H:i',
            ]);

            $this->audit->platform('tenant.provisioned', [
                'code' => $company->code,
                'admin_user_id' => $admin->id,
                'demo_data' => $demoData,
            ], $company, $actor);

            return [$company, $admin];
        });

        $admin->notify(new TenantAdminInvitation($company->id));

        if ($demoData) {
            SeedTenantDemoDataJob::dispatch($company->id, $admin->id, $actor->id)->afterCommit();
        }

        return $company;
    }
}
