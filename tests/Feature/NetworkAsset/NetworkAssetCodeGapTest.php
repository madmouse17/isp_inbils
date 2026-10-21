<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\NumberSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Models\NetworkAsset;
use RuntimeException;
use Tests\TestCase;

class NetworkAssetCodeGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app()['cache']->forget('spatie.permission.cache');

        $admin = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantPermissions($admin, ['network_asset.create', 'app.web']);
        $this->actingAs($admin);
    }

    public function test_a_failed_asset_create_gives_its_code_back(): void
    {
        $next = NumberSequenceService::peek('network_asset');

        NetworkAsset::creating(function (): void {
            throw new RuntimeException('insert failed');
        });

        $this->withoutExceptionHandling();

        try {
            $this->post(route('admin.network-assets.store'), ['name' => 'Router gap', 'asset_type' => 'router']);
            $this->fail('The create did not fail.');
        } catch (RuntimeException) {
            // expected: the create rolled back
        }

        $this->assertSame(0, NetworkAsset::query()->count());
        $this->assertSame($next, NumberSequenceService::peek('network_asset'), 'The failed create burned a code.');
    }
}
