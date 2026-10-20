<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * What staff read about RADIUS: every text is Indonesian, and a toast says what the router was told, never more. A
 * company without RADIUS gets a warning that nothing was sent, not a promise of a Disconnect.
 */
class RadiusStatusTextTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $this->coaAnswers((string) $this->nasClient($this->company)->nasname, 'ack');
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->actingAs($this->admin);
    }

    public function test_radius_messages_are_indonesian(): void
    {
        $realm = RadiusException::invalidRealm();

        $this->assertSame('RADIUS belum dikonfigurasi untuk perusahaan ini (Pengaturan → Integrasi).', RadiusException::disabled()->getMessage());
        $this->assertSame('Basis data RADIUS tidak dapat dihubungi; dicoba lagi otomatis.', RadiusException::unavailable()->getMessage());
        $this->assertSame([RadiusException::REJECTED, 'Kode perusahaan tidak bisa dipakai sebagai realm RADIUS.'], [$realm->kind, $realm->getMessage()]);
    }

    public function test_a_router_of_a_company_without_a_usable_realm_says_so_in_indonesian(): void
    {
        $company = Company::factory()->create(['is_active' => true, 'code' => 'ACME_CORP']);
        $nas = $this->nasClient($company);

        $written = CompanyService::runAs($company->id, fn (): bool => app(NetworkAccountProvisioner::class)->writeNas($nas));

        $this->assertFalse($written);
        $this->assertSame('Kode perusahaan tidak bisa dipakai sebagai realm RADIUS.', $nas->fresh()->last_error);
    }

    public function test_resync_toast_follows_radius_state(): void
    {
        $this->networkAccountFor($this->subscription);
        Queue::fake();
        $resync = fn () => $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();

        $resync()
            ->assertSessionHas('success', 'Sinkron ulang dimulai. Sesi pelanggan diputus setelah router menjawab.')
            ->assertSessionMissing('warning');
        $this->flushSession();

        $this->disableRadius();

        $resync()
            ->assertSessionHas('warning', 'Perubahan disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi untuk perusahaan ini.')
            ->assertSessionMissing('success');
    }

    public function test_resync_keeps_first_pending_time(): void
    {
        $account = $this->networkAccountFor($this->subscription);
        $since = now()->subMinutes(30);
        $account->forceFill(['pending_disconnect_at' => $since])->save();
        Queue::fake();

        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();
        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();

        $this->assertSame($since->toIso8601String(), $account->fresh()->pending_disconnect_at->toIso8601String(), 'a retry does not move the "since" of a Disconnect nobody answered');
    }

    public function test_block_and_unblock_toasts_follow_radius_state(): void
    {
        $this->networkAccountFor($this->subscription);
        $block = fn () => $this->post(route('admin.subscriptions.block', $this->subscription), ['reason' => 'abuse'])->assertRedirect();
        $unblock = fn () => $this->post(route('admin.subscriptions.unblock', $this->subscription))->assertRedirect();

        $block()->assertSessionHas('success', 'Blokir disimpan. Sesi pelanggan diputus setelah router menjawab.');
        $this->flushSession();
        $unblock()->assertSessionHas('success', 'Blokir dibuka. Sesi pelanggan diputus setelah router menjawab dan tersambung ulang dengan layanan normal.');
        $this->flushSession();

        $this->disableRadius();

        $block()->assertSessionHas('warning', 'Blokir disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi.')->assertSessionMissing('success');
        $this->flushSession();
        $unblock()->assertSessionHas('warning', 'Blokir dibuka, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi.')->assertSessionMissing('success');
    }

    public function test_a_subscription_without_a_network_account_has_nothing_to_tell_a_router(): void
    {
        $this->post(route('admin.subscriptions.block', $this->subscription), ['reason' => 'abuse'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Internet langganan diblokir.')
            ->assertSessionMissing('warning');
        $this->flushSession();

        $this->disableRadius();

        $this->post(route('admin.subscriptions.unblock', $this->subscription))
            ->assertRedirect()
            ->assertSessionHas('success', 'Blokir internet langganan dibuka.')
            ->assertSessionMissing('warning');
    }

    public function test_the_subscription_page_says_whether_the_company_has_radius(): void
    {
        $this->get(route('admin.subscriptions.show', $this->subscription))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('radiusEnabled', true));

        $this->disableRadius();

        $this->get(route('admin.subscriptions.show', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('radiusEnabled', false));
    }

    public function test_the_reactivate_dialog_is_told_where_the_next_invoice_moves_to(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->startOfDay());
        $this->subscription->forceFill(['status' => 'suspended', 'billing_day' => 5])->save();
        $page = fn () => $this->get(route('admin.subscriptions.show', $this->subscription))->assertOk();

        $page()->assertInertia(fn ($assert) => $assert->where('reactivationNextInvoiceDate', '2026-11-05'));

        $this->subscription->forceFill(['billing_day' => 25])->save();

        $page()->assertInertia(fn ($assert) => $assert->where('reactivationNextInvoiceDate', '2026-10-25'));

        $this->subscription->forceFill(['status' => 'active'])->save();

        $page()->assertInertia(fn ($assert) => $assert->where('reactivationNextInvoiceDate', null));
    }
}
