<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NasDeviceService;
use Modules\NetworkAsset\Services\NetworkAccountService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * Two creates race on one unique key: both pass the pre-check, the loser's insert hits the index. The loser must
 * answer 422 in Indonesian (or, for a membership, count as already granted), never a 500. The race is replayed
 * deterministically: a competing row is written between the pre-check and the insert.
 */
class UniqueRaceMessagesTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->createCompanyUser();
        $this->company = $admin->company;
        $this->fakeRadius();
        $this->actingAs($admin);
    }

    public function test_two_creates_racing_on_one_unique_answer_422_not_500(): void
    {
        $this->assertSame([422, 'Aset router ini sudah terdaftar sebagai router NAS.'], $this->nasRaceOnRouterAsset());
        $this->assertSame([422, 'Alamat ini tidak tersedia.'], $this->nasRaceOnAddress());
        $this->assertSame([422, 'Langganan ini sudah punya akun jaringan.'], $this->accountRaceOnSubscription());
        $this->assertSame([422, 'Nama pengguna ini tidak tersedia.'], $this->accountRaceOnUsername());
    }

    public function test_a_membership_granted_by_the_racing_request_counts_as_granted(): void
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id]);
        $racing = true;

        DB::listen(function (QueryExecuted $query) use (&$racing, $user): void {
            if ($racing && str_contains($query->sql, 'from `company_user`') && str_contains($query->sql, 'exists')) {
                $racing = false;
                DB::table('company_user')->insert([
                    'company_id' => $this->company->id,
                    'user_id' => $user->id,
                    'is_default' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        app(CompanyMembershipService::class)->grant($user, $this->company);

        $this->assertFalse($racing, 'The competing membership was never written.');
        $this->assertSame(1, DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $user->id)->count());
    }

    /** @return array{0: int, 1: string} */
    private function nasRaceOnRouterAsset(): array
    {
        $asset = $this->routerAsset('192.0.2.201');
        $this->racing(NasDevice::class, fn () => NasDevice::factory()->create([
            'company_id' => $this->company->id,
            'network_asset_id' => $asset->id,
            'nasname' => '203.0.113.201',
        ]));

        return $this->failure(fn () => NasDeviceService::create(['network_asset_id' => $asset->id, 'nasname' => '203.0.113.202', 'radius_secret' => 'fake-radius-secret-1']));
    }

    /** @return array{0: int, 1: string} */
    private function nasRaceOnAddress(): array
    {
        $asset = $this->routerAsset('192.0.2.203');
        $this->racing(NasDevice::class, fn () => NasDevice::factory()->create(['company_id' => $this->company->id, 'nasname' => '203.0.113.203']));

        return $this->failure(fn () => NasDeviceService::create(['network_asset_id' => $asset->id, 'nasname' => '203.0.113.203', 'radius_secret' => 'fake-radius-secret-1']));
    }

    /** @return array{0: int, 1: string} */
    private function accountRaceOnSubscription(): array
    {
        $subscription = $this->packagedSubscription();
        $this->racing(NetworkAccount::class, fn () => $this->networkAccountFor($subscription, ['username' => 'other@'.$this->realmOf($this->company)]));

        return $this->failure(fn () => NetworkAccountService::create($subscription, ['service_type' => 'pppoe', 'username' => 'racer.one']));
    }

    /** @return array{0: int, 1: string} */
    private function accountRaceOnUsername(): array
    {
        $subscription = $this->packagedSubscription();
        $other = $this->packagedSubscription();
        $this->racing(NetworkAccount::class, fn () => $this->networkAccountFor($other, ['username' => 'racer.two@'.$this->realmOf($this->company)]));

        return $this->failure(fn () => NetworkAccountService::create($subscription, ['service_type' => 'pppoe', 'username' => 'racer.two']));
    }

    private function routerAsset(string $ip): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'asset_type' => 'router',
            'management_ip' => $ip,
        ]);
    }

    private function packagedSubscription(): ServiceSubscription
    {
        return $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
    }

    /** Writes the competing row once, right before the model's own insert. */
    private function racing(string $model, Closure $competitor): void
    {
        $raced = false;

        $model::creating(function () use ($model, $competitor, &$raced): void {
            if (! $raced) {
                $raced = true;
                $model::withoutEvents($competitor);
            }
        });
    }

    /** @return array{0: int, 1: string} */
    private function failure(Closure $operation): array
    {
        try {
            $operation();
        } catch (HttpException $e) {
            return [$e->getStatusCode(), $e->getMessage()];
        }

        $this->fail('The losing create did not answer an HTTP error.');
    }
}
