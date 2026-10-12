<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Jobs\SyncNasDeviceJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Services\RouterOsApi;
use Modules\NetworkAsset\Services\RouterOsBinaryClient;
use Modules\NetworkAsset\Services\RouterOsClient;
use Modules\NetworkAsset\Services\RouterOsClients;
use Modules\NetworkAsset\Services\RouterOsException;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

/** What only the binary API client does: its wire words, TLS rules, timeouts, closing the socket, and per-router choice. */
class RouterOsBinaryClientTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    private User $admin;

    private NasDevice $nas;

    private FakeRouterOs $router;

    private RouterOsApi $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->fakeRouterOs();
        $this->nas = NasDevice::factory()->create(['company_id' => $this->admin->company_id, 'api_protocol' => 'binary', 'api_port' => 8729]);
        $this->router = $this->routerFor($this->nas)->profile('home-20m', 'isolir');
        $this->client = new RouterOsBinaryClient();
    }

    public function test_commands_use_the_menu_words_filters_and_proplist(): void
    {
        $id = $this->client->create($this->nas, 'pppoe', ['name' => 'alice', 'profile' => 'home-20m', 'disabled' => false]);
        $this->client->find($this->nas, 'pppoe', 'alice');
        $this->client->update($this->nas, 'pppoe', $id, ['disabled' => true]);
        $this->client->activeSessions($this->nas, 'hotspot', 'alice');
        $this->client->disconnect($this->nas, 'hotspot', '*1');
        $this->client->remove($this->nas, 'pppoe', $id);

        $this->assertSame([
            ['command' => '/ppp/secret/add', 'words' => ['=name=alice', '=profile=home-20m', '=disabled=false']],
            ['command' => '/ppp/secret/print', 'words' => ['?name=alice', '=.proplist=.id,name,profile,disabled,comment,service']],
            ['command' => '/ppp/secret/set', 'words' => ['=.id='.$id, '=disabled=true']],
            ['command' => '/ip/hotspot/active/print', 'words' => ['?user=alice', '=.proplist=.id,user,address']],
            ['command' => '/ip/hotspot/active/remove', 'words' => ['=.id=*1']],
            ['command' => '/ppp/secret/remove', 'words' => ['=.id='.$id]],
        ], $this->router->commands());
    }

    public function test_the_tls_port_verifies_the_certificate_against_the_system_store(): void
    {
        $this->client->systemResource($this->nas);

        $config = $this->router->connections()[0];
        $this->assertTrue($config['ssl']);
        $this->assertSame(8729, $config['port']);
        $this->assertSame(['ciphers' => 'DEFAULT', 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false], $config['ssl_options']);
    }

    public function test_every_port_but_8728_is_tls_and_8728_is_the_only_plain_one(): void
    {
        foreach ([28729 => true, 9999 => true, 8728 => false] as $port => $tls) {
            $nas = NasDevice::factory()->create(['company_id' => $this->admin->company_id, 'api_protocol' => 'binary', 'api_port' => $port]);
            $router = $this->routerFor($nas);

            $this->client->systemResource($nas);

            $this->assertSame($tls, $router->connections()[0]['ssl']);
            $this->assertSame($tls, isset($router->connections()[0]['ssl_options']['verify_peer']));
        }
    }

    public function test_the_configured_ca_bundle_is_used_for_verification(): void
    {
        config(['services.routeros.ca_bundle' => '/etc/ssl/inbils-routers.pem']);

        $this->client->systemResource($this->nas);

        $this->assertSame('/etc/ssl/inbils-routers.pem', $this->router->connections()[0]['ssl_options']['cafile']);
        $this->assertTrue($this->router->connections()[0]['ssl_options']['verify_peer']);
    }

    public function test_the_plain_port_is_clear_text_and_never_touches_tls_options(): void
    {
        $nas = NasDevice::factory()->create(['company_id' => $this->admin->company_id, 'api_protocol' => 'binary', 'api_port' => 8728]);
        $plain = $this->routerFor($nas);

        $this->client->systemResource($nas);

        $config = $plain->connections()[0];
        $this->assertFalse($config['ssl']);
        $this->assertArrayNotHasKey('ssl_options', $config);
    }

    public function test_one_attempt_short_timeouts_and_the_credentials_of_the_device(): void
    {
        $this->client->systemResource($this->nas);

        $config = $this->router->connections()[0];
        $this->assertSame([5, 10, 1, 0], [$config['timeout'], $config['socket_timeout'], $config['attempts'], $config['delay']]);
        $this->assertSame([$this->nas->api_username, $this->nas->api_password, $this->nas->networkAsset->management_ip], [$config['user'], $config['pass'], $config['host']]);
    }

    public function test_the_socket_is_closed_after_a_call_and_after_a_failed_one(): void
    {
        $this->client->systemResource($this->nas);
        $this->router->down('server');
        try {
            $this->client->systemResource($this->nas);
        } catch (RouterOsException) {
        }

        $streams = array_column($this->router->connections(), 'stream');
        $this->assertCount(2, $streams);
        foreach ($streams as $stream) {
            $this->assertFalse(is_resource($stream));
        }
    }

    public function test_an_ipv6_management_address_is_bracketed(): void
    {
        $this->nas->networkAsset->forceFill(['management_ip' => '2001:db8::1'])->save();
        $v6 = $this->routerAt('2001:db8::1', 8729);

        $this->client->systemResource($this->nas->fresh('networkAsset'));

        $this->assertSame('[2001:db8::1]', $v6->connections()[0]['host']);
    }

    public function test_the_resolver_hands_each_device_its_own_client(): void
    {
        $rest = NasDevice::factory()->create(['company_id' => $this->admin->company_id]);
        $resolver = app(RouterOsClients::class);

        $this->assertInstanceOf(RouterOsClient::class, $resolver->for($rest));
        $this->assertInstanceOf(RouterOsBinaryClient::class, $resolver->for($this->nas));
    }

    public function test_the_provisioner_talks_binary_to_a_binary_device_and_never_to_the_rest_port(): void
    {
        $nas = NasDevice::factory()->writable()->create(['company_id' => $this->admin->company_id, 'api_protocol' => 'binary', 'api_port' => 8729]);
        $router = $this->routerFor($nas)->profile('home-20m', 'isolir');
        $subscription = $this->subscriptionIn($this->admin->company, 'active', null, $this->packageWithRouterProfile($this->admin->company, 'home-20m'));
        $account = $this->networkAccountFor($subscription, $nas);

        $this->applyAccount($account);

        $this->assertSame('home-20m', $router->account('pppoe', 'alice')['profile']);
        $this->assertSame([], $router->requests());
        $this->assertNotSame([], $router->connections());
        $this->assertSame('synced', $account->fresh()->sync_status);
    }

    public function test_one_sync_run_reads_a_rest_router_and_a_binary_router_each_over_its_own_api(): void
    {
        $company = $this->admin->company;
        [$restNas, $restRouter] = $this->nasRouter($company);
        $binaryNas = NasDevice::factory()->create(['company_id' => $company->id, 'api_protocol' => 'binary', 'api_port' => 8729]);
        $binaryRouter = $this->routerFor($binaryNas);
        $restRouter->seedAccount('pppoe', 'stray-rest');
        $binaryRouter->seedAccount('pppoe', 'stray-binary');

        foreach ([$restNas, $binaryNas] as $nas) {
            $this->app->call([new SyncNasDeviceJob((int) $company->id, $nas->id), 'handle']);
        }

        $this->assertSame(['stray-rest'], $restNas->fresh()->unmanaged_usernames);
        $this->assertSame(['stray-binary'], $binaryNas->fresh()->unmanaged_usernames);
        $this->assertSame([], $restRouter->connections());
        $this->assertSame([], $binaryRouter->requests());
    }
}
