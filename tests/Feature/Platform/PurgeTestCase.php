<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\SetupWizardService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tenants the way platform provisioning leaves them, and the state a tenant must be in before a purge. */
abstract class PurgeTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->seed(SystemSettingSeeder::class);
    }

    /** @return array{0: Company, 1: User} a company with its first admin, created through the provisioning service */
    protected function newTenant(string $code): array
    {
        $admin = User::query()->create(['name' => "Admin {$code}", 'email' => strtolower($code).'-admin@tenant.test', 'password' => Str::random(32)]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $company = app(SetupWizardService::class)->createFor($admin, [
            'name' => "Tenant {$code}",
            'code' => $code,
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y',
            'datetime_format' => 'd M Y H:i',
            'admin_name' => "Admin {$code}",
        ]);

        return [$company, $admin->refresh()];
    }

    /** A subsidiary of $head with the defaults every company gets, and nothing else. */
    protected function subsidiary(Company $head, string $code): Company
    {
        return app(CompanyHierarchyService::class)->createSubsidiary($head, ['name' => "Cabang {$code}", 'code' => $code]);
    }

    /**
     * Puts the tenant (the Head and its subsidiaries) where a purge accepts it: suspended $daysAgo days ago, deletion
     * requested, and an export that finished after the suspension. Written straight to the tables, the way the
     * lifecycle service of the platform panel leaves them.
     */
    protected function makePurgeable(Company $head, int $daysAgo = 31): PlatformAdmin
    {
        $ids = [$head->id, ...Company::query()->where('parent_company_id', $head->id)->pluck('id')->all()];
        $suspended = now()->subDays($daysAgo);

        DB::table('companies')->whereIn('id', $ids)->update(['is_active' => false, 'suspended_at' => $suspended]);
        DB::table('companies')->where('id', $head->id)->update(['deletion_requested_at' => $suspended->copy()->addDay()]);

        $operator = PlatformAdmin::factory()->create();
        TenantExport::factory()->ready($suspended->copy()->addDays(2)->toDateTimeString())->create([
            'company_id' => $head->id,
            'requested_by_platform_admin_id' => $operator->id,
        ]);

        return $operator;
    }
}
