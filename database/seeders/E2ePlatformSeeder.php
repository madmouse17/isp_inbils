<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\SetupWizardService;
use Illuminate\Database\Seeder;

class E2ePlatformSeeder extends Seeder
{
    public const OPERATOR_EMAIL = 'operator@platform.e2e.inbils.test';

    public const ENROL_EMAIL = 'operator-enrol@platform.e2e.inbils.test';

    public const PASSWORD = 'E2ePlatformPass123!';

    public const TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public const TENANT_CODE = 'E2E-SUSPEND';

    public const TENANT_ADMIN_EMAIL = 'admin@e2e-suspend.inbils.test';

    public const TENANT_ADMIN_PASSWORD = 'E2eTenantPass123!';

    public function run(): void
    {
        if (! app()->environment('e2e')) {
            throw new \RuntimeException('E2ePlatformSeeder only runs in the e2e environment.');
        }

        PlatformAdmin::query()->updateOrCreate(
            ['email' => self::OPERATOR_EMAIL],
            ['name' => 'E2E Platform Operator', 'password' => self::PASSWORD, 'is_active' => true],
        )->forceFill([
            'two_factor_secret' => self::TOTP_SECRET,
            'two_factor_recovery_codes' => [
                'alpha-bravo1',
                'bravo-charl2',
                'charl-delta3',
                'delta-echo44',
                'echoo-fox55',
                'foxtr-golf6',
                'golff-hotel',
                'hotel-india',
            ],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ])->save();

        PlatformAdmin::query()->updateOrCreate(
            ['email' => self::ENROL_EMAIL],
            ['name' => 'E2E Enrol Operator', 'password' => self::PASSWORD, 'is_active' => true],
        )->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        $admin = User::query()->updateOrCreate(
            ['email' => self::TENANT_ADMIN_EMAIL],
            [
                'name' => 'E2E Suspend Admin',
                'password' => self::TENANT_ADMIN_PASSWORD,
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        if ($admin->company_id === null) {
            app(SetupWizardService::class)->createFor($admin, [
                'name' => 'E2E Suspend Tenant',
                'code' => self::TENANT_CODE,
                'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR',
                'date_format' => 'd M Y',
                'datetime_format' => 'd M Y H:i',
                'admin_name' => $admin->name,
            ]);
        }
    }
}
