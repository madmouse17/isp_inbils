<?php

namespace Tests\Unit;

use App\Models\Core\Company;
use App\Models\Core\Setting;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CompanyService::resetCache();
    }

    public function test_setting_with_no_company_falls_back_to_default_setting(): void
    {
        Setting::query()->create([
            'key' => 'default_currency',
            'value' => 'IDR',
            'group' => 'default',
            'type' => 'string',
        ]);

        $this->assertSame('IDR', CompanyService::setting('currency'));
    }

    public function test_setting_with_company_uses_company_specific_value(): void
    {
        $company = Company::query()->create([
            'name' => 'Tenant A',
            'code' => 'TA',
            'settings' => ['currency' => 'USD'],
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user);
        CompanyService::resetCache();

        $this->assertSame('USD', CompanyService::setting('currency'));
    }

    public function test_current_id_is_null_without_auth(): void
    {
        $this->assertNull(CompanyService::currentId());
    }

    public function test_update_settings_merges_existing_and_new_keys(): void
    {
        $company = Company::query()->create([
            'name' => 'Tenant A',
            'code' => 'TA',
            'settings' => ['currency' => 'IDR', 'date_format' => 'd M Y'],
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user);
        CompanyService::resetCache();

        $updated = CompanyService::updateSettings([
            'currency' => 'USD',
            'timezone' => 'Asia/Singapore',
        ]);

        $this->assertEquals([
            'currency' => 'USD',
            'date_format' => 'd M Y',
            'timezone' => 'Asia/Singapore',
        ], $updated->settings);
    }
}
