<?php

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

class ImportAssetIpsCommandTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIpamOdnWorld();
        $this->app['auth']->logout();
    }

    /**
     * AST-A: two good values. AST-B: a duplicate of A's and a typo. AST-C: a free IPv6 value and a duplicate
     * of A's second value. AST-D: nothing typed.
     */
    private function legacyAssets(): void
    {
        $this->makeAsset($this->company, 'router', null, ['code' => 'AST-A', 'ip_address' => '10.90.0.1', 'management_ip' => '10.90.0.2']);
        $this->makeAsset($this->company, 'switch', null, ['code' => 'AST-B', 'ip_address' => '10.90.0.1', 'management_ip' => 'not-an-ip']);
        $this->makeAsset($this->company, 'olt', null, ['code' => 'AST-C', 'ip_address' => '2001:DB8::7', 'management_ip' => '10.90.0.2']);
        $this->makeAsset($this->company, 'odp', null, ['code' => 'AST-D']);
    }

    /** @return list<array<string, mixed>> */
    private function assetsSnapshot(): array
    {
        return NetworkAsset::withoutCompany()->orderBy('id')->get(['id', 'code', 'ip_address', 'management_ip', 'updated_at'])->toArray();
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->legacyAssets();
        $before = $this->assetsSnapshot();

        $this->artisan('ipam:import-asset-ips', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain("[dry run] company {$this->company->id} ({$this->company->name}): imported 3, already registered 0, unparseable 1, duplicates 2")
            ->assertExitCode(0);

        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertSame($before, $this->assetsSnapshot());
    }

    public function test_the_real_run_registers_the_unique_values_and_is_idempotent(): void
    {
        $this->legacyAssets();
        $before = $this->assetsSnapshot();

        $this->artisan('ipam:import-asset-ips', ['--company' => $this->company->id])
            ->expectsOutputToContain('imported 3, already registered 0, unparseable 1, duplicates 2')
            ->expectsOutputToContain('not-an-ip')
            ->assertExitCode(0);

        $held = IpAddress::query()->forCompany($this->company->id)->orderBy('id')->get();
        $this->assertSame(['10.90.0.1', '10.90.0.2', '2001:db8::7'], $held->pluck('address')->all(), 'the first asset wins a duplicate; the typo is skipped');
        $this->assertSame(
            [
                NetworkAsset::withoutCompany()->where('code', 'AST-A')->value('id'),
                NetworkAsset::withoutCompany()->where('code', 'AST-A')->value('id'),
                NetworkAsset::withoutCompany()->where('code', 'AST-C')->value('id'),
            ],
            $held->pluck('network_asset_id')->all(),
        );
        $this->assertSame($before, $this->assetsSnapshot(), 'assets are never edited');

        $this->artisan('ipam:import-asset-ips', ['--company' => $this->company->id])
            ->expectsOutputToContain('imported 0, already registered 3, unparseable 1, duplicates 2')
            ->assertExitCode(0);

        $this->assertSame(3, IpAddress::query()->forCompany($this->company->id)->count());
    }

    public function test_the_report_names_the_asset_and_the_holder_of_every_duplicate(): void
    {
        $this->legacyAssets();

        $this->artisan('ipam:import-asset-ips', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('Duplicates (not imported):')
            ->expectsOutputToContain('held by asset AST-A')
            ->assertExitCode(0);
    }

    public function test_a_value_held_by_a_subscription_or_a_reservation_is_reported_not_imported(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->inCompany($this->company, function () use ($subscription): void {
            $pool = IpPrefixService::create(['cidr' => '10.91.0.0/24', 'usage' => 'customer_static']);
            IpAssignmentService::assignToSubscription($subscription, $pool, '10.91.0.5');
            IpAssignmentService::reserve($pool, '10.91.0.6', 'gateway');
        });
        $this->makeAsset($this->company, 'router', null, ['code' => 'AST-X', 'ip_address' => '10.91.0.5', 'management_ip' => '10.91.0.6']);

        $this->artisan('ipam:import-asset-ips', ['--company' => $this->company->id])
            ->expectsOutputToContain('imported 0, already registered 0, unparseable 0, duplicates 2')
            ->expectsOutputToContain('held by subscription '.$subscription->code)
            ->expectsOutputToContain('reserved (gateway)')
            ->assertExitCode(0);

        $this->assertSame(2, IpAddress::query()->forCompany($this->company->id)->count());
    }

    public function test_without_a_company_option_every_company_is_processed_separately(): void
    {
        $other = $this->otherCompany();
        $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.92.0.1']);
        $this->makeAsset($other, 'router', null, ['ip_address' => '10.92.0.1']);

        $this->artisan('ipam:import-asset-ips')->assertExitCode(0);

        $this->assertSame(1, IpAddress::query()->forCompany($this->company->id)->count());
        $this->assertSame(1, IpAddress::query()->forCompany($other->id)->count(), 'the same address in another company is not a duplicate');
    }

    public function test_an_unknown_company_or_a_non_numeric_option_fails(): void
    {
        $this->artisan('ipam:import-asset-ips', ['--company' => 999999])->expectsOutputToContain('No company matches.')->assertExitCode(1);
        $this->artisan('ipam:import-asset-ips', ['--company' => 'abc'])->expectsOutputToContain('--company must be a company id.')->assertExitCode(1);
    }

    public function test_soft_deleted_assets_are_left_out(): void
    {
        $asset = $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.93.0.1']);
        $asset->delete();

        $this->artisan('ipam:import-asset-ips', ['--company' => $this->company->id])->assertExitCode(0);

        $this->assertSame(0, IpAddress::query()->forCompany($this->company->id)->count());
    }
}
