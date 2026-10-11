<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\RouterOsClient;
use Modules\NetworkAsset\Services\RouterOsException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

class RouterOsClientTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    private User $admin;

    private NasDevice $nas;

    private FakeRouterOs $router;

    private RouterOsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->fakeRouterOs();
        $this->nas = NasDevice::factory()->create(['company_id' => $this->admin->company_id]);
        $this->router = $this->routerFor($this->nas)->profile('home-20m', 'isolir');
        $this->client = new RouterOsClient();
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> type, accounts menu, sessions menu, session login key */
    public static function serviceTypes(): array
    {
        return [
            'pppoe' => ['pppoe', 'ppp/secret', 'ppp/active', 'name'],
            'hotspot' => ['hotspot', 'ip/hotspot/user', 'ip/hotspot/active', 'user'],
        ];
    }

    /** @param  list<array<string, mixed>>  $requests */
    private function assertOneRequest(array $requests, string $method, string $path): array
    {
        $this->assertCount(1, $requests);
        $this->assertSame($method, $requests[0]['method']);
        $this->assertSame($path, $requests[0]['path']);

        return $requests[0];
    }

    private function exception(callable $call): RouterOsException
    {
        try {
            $call();
        } catch (RouterOsException $e) {
            return $e;
        }

        $this->fail('Expected a RouterOsException.');
    }

    public function test_system_resource_reads_version_board_and_uptime(): void
    {
        $resource = $this->client->systemResource($this->nas);

        $this->assertSame(['version' => '7.16.2 (stable)', 'board_name' => 'CHR', 'uptime' => '3d4h5m'], $resource);
        $request = $this->assertOneRequest($this->router->requests(), 'GET', 'system/resource');
        $this->assertSame([], $request['query']);
    }

    #[DataProvider('serviceTypes')]
    public function test_find_filters_by_name_and_asks_only_for_the_needed_properties(string $type, string $menu): void
    {
        $this->router->seedAccount($type, 'alice', 'home-20m', true, 'fake-pw', 'inbils:CO:SUB-1');
        $this->router->seedAccount($type, 'bob', 'default');
        $this->router->forgetRequests();

        $found = $this->client->find($this->nas, $type, 'alice');

        $this->assertSame(['name' => 'alice', 'profile' => 'home-20m', 'disabled' => true, 'comment' => 'inbils:CO:SUB-1', 'service' => $type === 'pppoe' ? 'pppoe' : null], array_diff_key($found, ['id' => 1]));
        $this->assertMatchesRegularExpression('/^\*[0-9A-F]+$/', $found['id']);
        $request = $this->assertOneRequest($this->router->requests(), 'GET', $menu);
        $this->assertSame(['name' => 'alice', '.proplist' => '.id,name,profile,disabled,comment'.($type === 'pppoe' ? ',service' : '')], $request['query']);
        $this->assertNull($this->client->find($this->nas, $type, 'nobody'));
    }

    #[DataProvider('serviceTypes')]
    public function test_create_puts_the_fields_as_strings_and_returns_the_new_id(string $type, string $menu): void
    {
        $id = $this->client->create($this->nas, $type, ['name' => 'alice', 'password' => 'fake-pw', 'profile' => 'home-20m', 'comment' => 'inbils:CO:SUB-1', 'disabled' => false]);

        $request = $this->assertOneRequest($this->router->requests(), 'PUT', $menu);
        $this->assertSame(['name', 'password', 'profile', 'comment', 'disabled'], array_keys($request['body']));
        $this->assertSame('false', $request['body']['disabled']);
        $this->assertSame($id, $this->router->account($type, 'alice')['.id']);
        $this->assertSame('home-20m', $this->router->account($type, 'alice')['profile']);
    }

    #[DataProvider('serviceTypes')]
    public function test_update_patches_the_record_by_id(string $type, string $menu): void
    {
        $id = $this->router->seedAccount($type, 'alice', 'home-20m');
        $this->router->forgetRequests();

        $this->client->update($this->nas, $type, $id, ['profile' => 'isolir', 'disabled' => true]);

        $request = $this->assertOneRequest($this->router->requests(), 'PATCH', $menu.'/'.$id);
        $this->assertSame(['profile' => 'isolir', 'disabled' => 'true'], $request['body']);
        $this->assertSame('isolir', $this->router->account($type, 'alice')['profile']);
        $this->assertSame('true', $this->router->account($type, 'alice')['disabled']);
    }

    #[DataProvider('serviceTypes')]
    public function test_remove_deletes_by_id_and_a_missing_record_counts_as_removed(string $type, string $menu): void
    {
        $id = $this->router->seedAccount($type, 'alice');
        $this->router->forgetRequests();

        $this->client->remove($this->nas, $type, $id);
        $this->client->remove($this->nas, $type, $id);

        $this->assertNull($this->router->account($type, 'alice'));
        $requests = $this->router->requests();
        $this->assertCount(2, $requests);
        $this->assertSame(['DELETE', $menu.'/'.$id], [$requests[1]['method'], $requests[1]['path']]);
    }

    #[DataProvider('serviceTypes')]
    public function test_active_sessions_are_listed_by_login_and_dropped_by_id(string $type, string $menu, string $sessionsMenu, string $login): void
    {
        $alice = $this->router->seedSession($type, 'alice', '10.10.0.5');
        $this->router->seedSession($type, 'bob', '10.10.0.6');
        $this->router->forgetRequests();

        $sessions = $this->client->activeSessions($this->nas, $type, 'alice');
        $all = $this->client->activeSessions($this->nas, $type);

        $this->assertSame([['id' => $alice, 'name' => 'alice', 'address' => '10.10.0.5']], $sessions);
        $this->assertCount(2, $all);
        $requests = $this->router->requests();
        $this->assertSame($sessionsMenu, $requests[0]['path']);
        $this->assertSame([$login => 'alice', '.proplist' => ".id,{$login},address"], $requests[0]['query']);
        $this->assertSame(['.proplist' => ".id,{$login},address"], $requests[1]['query']);

        $this->client->disconnect($this->nas, $type, $alice);
        $this->client->disconnect($this->nas, $type, $alice);

        $this->assertSame([], $this->router->sessions($type, 'alice'));
        $this->assertCount(1, $this->router->sessions($type));
    }

    #[DataProvider('serviceTypes')]
    public function test_list_accounts_reads_every_account_without_passwords(string $type, string $menu): void
    {
        $this->router->seedAccount($type, 'alice', 'home-20m', false, 'fake-pw-a', 'inbils:CO:SUB-1');
        $this->router->seedAccount($type, 'bob', 'isolir', true);
        $this->router->forgetRequests();

        $accounts = $this->client->listAccounts($this->nas, $type);

        $this->assertSame(['alice', 'bob'], array_column($accounts, 'name'));
        $this->assertSame([false, true], array_column($accounts, 'disabled'));
        $this->assertSame(['inbils:CO:SUB-1', null], array_column($accounts, 'comment'));
        $this->assertStringNotContainsString('fake-pw-a', json_encode($accounts));
        $request = $this->assertOneRequest($this->router->requests(), 'GET', $menu);
        $this->assertSame(['.proplist' => '.id,name,profile,disabled,comment'.($type === 'pppoe' ? ',service' : '')], $request['query']);
    }

    public function test_every_call_uses_basic_auth_a_verified_tls_session_no_redirects_and_short_timeouts(): void
    {
        $this->client->systemResource($this->nas);
        $this->client->find($this->nas, 'pppoe', 'alice');
        $this->client->activeSessions($this->nas, 'hotspot');

        foreach ($this->router->requests() as $request) {
            $this->assertSame('inbils-api', $request['auth_user']);
            $this->assertTrue($request['has_password']);
            $this->assertTrue($request['verify']);
            $this->assertFalse($request['allow_redirects']);
            $this->assertEquals(5, $request['connect_timeout']);
            $this->assertEquals(10, $request['timeout']);
        }
    }

    public function test_the_ca_bundle_path_is_used_for_verification_when_configured(): void
    {
        config(['services.routeros.ca_bundle' => '/etc/ssl/noc/ca.pem']);

        $this->client->systemResource($this->nas);

        $this->assertSame('/etc/ssl/noc/ca.pem', $this->router->requests()[0]['verify']);
    }

    public function test_the_api_password_is_sent_only_as_the_basic_auth_credential(): void
    {
        $this->client->find($this->nas, 'pppoe', 'alice');

        Http::assertSent(function ($request): bool {
            $this->assertStringNotContainsString('fake-router-api-password', (string) $request->url());
            $this->assertStringNotContainsString('fake-router-api-password', $request->body());

            return true;
        });
    }

    /** @return array<string, array{0: string, 1: string}> failure mode, expected kind */
    public static function failures(): array
    {
        return [
            'connection refused' => ['refused', 'unreachable'],
            'timeout' => ['timeout', 'unreachable'],
            'tls failure' => ['tls', 'tls'],
            '401' => ['auth', 'auth'],
            '403' => ['forbidden', 'auth'],
            '400' => ['rejected', 'rejected'],
            '500' => ['server', 'server'],
            'redirect' => ['redirect', 'server'],
            'not routeros json' => ['garbage', 'server'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_map_to_a_kind(string $failure, string $kind): void
    {
        $this->router->down($failure);

        $exception = $this->exception(fn () => $this->client->find($this->nas, 'pppoe', 'alice'));

        $this->assertSame($kind, $exception->kind);
        $this->assertSame(in_array($kind, ['unreachable', 'tls', 'server'], true), $exception->retryable());
    }

    public function test_a_redirect_is_not_followed(): void
    {
        $this->router->down('redirect');

        $this->exception(fn () => $this->client->systemResource($this->nas));

        $this->assertCount(1, $this->router->requests());
    }

    public function test_a_rejection_carries_only_the_routeros_message_and_detail(): void
    {
        $this->router->down('rejected', "input does not match any value of profile\x07\n");

        $exception = $this->exception(fn () => $this->client->create($this->nas, 'pppoe', ['name' => 'alice', 'profile' => 'nope']));

        $this->assertSame('Bad Request: input does not match any value of profile', $exception->getMessage());
        $this->assertSame(400, $exception->status);
    }

    public function test_a_long_routeros_message_is_capped_at_255_characters(): void
    {
        $this->router->down('rejected', str_repeat('x', 600));

        $exception = $this->exception(fn () => $this->client->find($this->nas, 'pppoe', 'alice'));

        $this->assertSame(255, strlen($exception->getMessage()));
    }

    public function test_a_non_routeros_body_is_never_part_of_the_message(): void
    {
        $this->router->down('server');

        $exception = $this->exception(fn () => $this->client->find($this->nas, 'pppoe', 'alice'));

        $this->assertStringNotContainsString('fake-leaked-secret-123', $exception->getMessage());
        $this->assertStringNotContainsString('<html>', $exception->getMessage());
    }

    public function test_a_json_body_without_a_routeros_message_is_never_echoed(): void
    {
        Http::fake(['*' => Http::response(['error' => 400, 'secret' => 'fake-leaked-secret-456'], 400)]);
        $unknown = NasDevice::factory()->create(['company_id' => $this->admin->company_id]);
        // The router fake answers known hosts first; this host is nobody's, so the catch-all above answers.
        NetworkAsset::withoutCompany()->whereKey($unknown->network_asset_id)->update(['management_ip' => '198.51.100.77']);

        $exception = $this->exception(fn () => $this->client->find($unknown->fresh(), 'pppoe', 'alice'));

        $this->assertSame('rejected', $exception->kind);
        $this->assertStringNotContainsString('fake-leaked-secret-456', $exception->getMessage());
    }

    public function test_with_the_installation_switch_off_nothing_is_sent_not_even_a_read(): void
    {
        $this->disableRouterOs();

        foreach ([
            fn () => $this->client->systemResource($this->nas),
            fn () => $this->client->find($this->nas, 'pppoe', 'alice'),
            fn () => $this->client->create($this->nas, 'pppoe', ['name' => 'alice']),
            fn () => $this->client->listAccounts($this->nas, 'hotspot'),
            fn () => $this->client->disconnect($this->nas, 'pppoe', '*1'),
        ] as $call) {
            $this->assertSame('disabled', $this->exception($call)->kind);
        }

        Http::assertNothingSent();
        $this->assertSame([], $this->router->requests());
    }

    public function test_an_asset_without_a_valid_management_ip_is_refused_before_any_request(): void
    {
        foreach ([null, 'router.example.test/../x', '999.1.1.1'] as $ip) {
            NetworkAsset::withoutCompany()->whereKey($this->nas->network_asset_id)->update(['management_ip' => $ip]);

            $this->assertSame('rejected', $this->exception(fn () => $this->client->systemResource($this->nas->fresh()))->kind);
        }

        $this->assertSame([], $this->router->requests());
    }

    public function test_a_record_id_that_is_not_a_routeros_id_never_reaches_a_url(): void
    {
        foreach (['../../system/reboot', '*1/../2', '', 'abc'] as $id) {
            $this->assertSame('server', $this->exception(fn () => $this->client->remove($this->nas, 'pppoe', $id))->kind);
            $this->assertSame('server', $this->exception(fn () => $this->client->update($this->nas, 'pppoe', $id, ['profile' => 'isolir']))->kind);
            $this->assertSame('server', $this->exception(fn () => $this->client->disconnect($this->nas, 'pppoe', $id))->kind);
        }

        $this->assertSame([], $this->router->requests());
    }

    public function test_a_call_to_a_router_nobody_faked_is_refused_as_a_stray_request(): void
    {
        NetworkAsset::withoutCompany()->whereKey($this->nas->network_asset_id)->update(['management_ip' => '198.51.100.55']);

        $this->expectException(StrayRequestException::class);

        $this->client->systemResource($this->nas->fresh());
    }

    public function test_an_ipv6_management_address_is_bracketed(): void
    {
        NetworkAsset::withoutCompany()->whereKey($this->nas->network_asset_id)->update(['management_ip' => '2001:db8::1']);
        $v6 = $this->routerAt('2001:db8::1', $this->nas->api_port);

        $this->client->systemResource($this->nas->fresh());

        $this->assertCount(1, $v6->requests());
    }

    public function test_tenant_scope_still_applies_to_the_router_asset_lookup(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = NasDevice::factory()->create(['company_id' => $other->id]);

        // The asset belongs to another company than the ambient one: fail closed, nothing is sent.
        $this->assertSame('rejected', $this->exception(fn () => $this->client->systemResource($foreign))->kind);
    }
}
