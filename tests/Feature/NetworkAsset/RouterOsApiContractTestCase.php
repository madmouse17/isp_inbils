<?php

namespace Tests\Feature\NetworkAsset;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Services\RouterOsApi;
use Modules\NetworkAsset\Services\RouterOsClients;
use Modules\NetworkAsset\Services\RouterOsException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

/**
 * What every RouterOS client must do the same way, run once per API (REST and binary) against the same
 * in-memory router: return shapes, "already gone is fine", error kinds, the installation switch, id checks.
 */
abstract class RouterOsApiContractTestCase extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    protected NasDevice $nas;

    protected FakeRouterOs $router;

    protected RouterOsApi $client;

    abstract protected function protocol(): string;

    abstract protected function port(): int;

    abstract protected function expectedClient(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->createCompanyUser();
        $this->fakeRouterOs();
        $this->nas = NasDevice::factory()->create(['company_id' => $admin->company_id, 'api_protocol' => $this->protocol(), 'api_port' => $this->port()]);
        $this->router = $this->routerFor($this->nas)->profile('home-20m', 'isolir');
        $this->client = app(RouterOsClients::class)->for($this->nas);
    }

    /** @return array<string, array{0: string}> */
    public static function serviceTypes(): array
    {
        return ['pppoe' => ['pppoe'], 'hotspot' => ['hotspot']];
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

    public function test_the_resolver_picks_this_api_for_the_device(): void
    {
        $this->assertInstanceOf($this->expectedClient(), $this->client);
    }

    public function test_system_resource_reads_version_board_and_uptime(): void
    {
        $this->assertSame(['version' => '7.16.2 (stable)', 'board_name' => 'CHR', 'uptime' => '3d4h5m'], $this->client->systemResource($this->nas));
    }

    #[DataProvider('serviceTypes')]
    public function test_find_returns_the_account_shape_or_null(string $type): void
    {
        $this->router->seedAccount($type, 'alice', 'home-20m', true, 'fake-pw', 'inbils:CO:SUB-1');
        $this->router->seedAccount($type, 'bob');

        $found = $this->client->find($this->nas, $type, 'alice');

        $this->assertSame(['name' => 'alice', 'profile' => 'home-20m', 'disabled' => true, 'comment' => 'inbils:CO:SUB-1', 'service' => $type === 'pppoe' ? 'pppoe' : null], array_diff_key($found, ['id' => 1]));
        $this->assertMatchesRegularExpression('/^\*[0-9A-F]+$/', $found['id']);
        $this->assertNull($this->client->find($this->nas, $type, 'nobody'));
    }

    #[DataProvider('serviceTypes')]
    public function test_create_update_and_remove_change_the_router(string $type): void
    {
        $id = $this->client->create($this->nas, $type, ['name' => 'alice', 'password' => 'fake-pw', 'profile' => 'home-20m', 'comment' => 'inbils:CO:SUB-1', 'disabled' => false]);

        $this->assertSame($id, $this->router->account($type, 'alice')['.id']);
        $row = $this->router->account($type, 'alice');
        $this->assertSame(['home-20m', 'false', 'fake-pw'], [$row['profile'], $row['disabled'], $row['password']]);

        $this->client->update($this->nas, $type, $id, ['profile' => 'isolir', 'disabled' => true]);
        $this->assertSame(['isolir', 'true'], [$this->router->account($type, 'alice')['profile'], $this->router->account($type, 'alice')['disabled']]);

        $this->client->remove($this->nas, $type, $id);
        $this->client->remove($this->nas, $type, $id);
        $this->assertNull($this->router->account($type, 'alice'));
    }

    #[DataProvider('serviceTypes')]
    public function test_a_router_refusal_is_a_rejection_with_the_routeros_message(string $type): void
    {
        $this->router->seedAccount($type, 'alice');

        $duplicate = $this->exception(fn () => $this->client->create($this->nas, $type, ['name' => 'alice']));
        $badProfile = $this->exception(fn () => $this->client->create($this->nas, $type, ['name' => 'bob', 'profile' => 'nope']));

        $this->assertSame(RouterOsException::REJECTED, $duplicate->kind);
        $this->assertStringContainsString('already exists', $duplicate->getMessage());
        $this->assertSame(RouterOsException::REJECTED, $badProfile->kind);
        $this->assertStringContainsString('profile', $badProfile->getMessage());
    }

    #[DataProvider('serviceTypes')]
    public function test_active_sessions_are_listed_by_login_and_dropped_by_id(string $type): void
    {
        $mine = $this->router->seedSession($type, 'alice', '10.10.0.5');
        $this->router->seedSession($type, 'bob', '10.10.0.6');

        $this->assertSame([['id' => $mine, 'name' => 'alice', 'address' => '10.10.0.5']], $this->client->activeSessions($this->nas, $type, 'alice'));
        $this->assertCount(2, $this->client->activeSessions($this->nas, $type));

        $this->client->disconnect($this->nas, $type, $mine);
        $this->client->disconnect($this->nas, $type, $mine);
        $this->assertSame([], $this->router->sessions($type, 'alice'));
    }

    #[DataProvider('serviceTypes')]
    public function test_list_accounts_reads_every_account_without_secrets(string $type): void
    {
        $this->router->seedAccount($type, 'alice', 'home-20m');
        $this->router->seedAccount($type, 'bob', comment: 'manual');

        $accounts = $this->client->listAccounts($this->nas, $type);

        $this->assertSame(['alice', 'bob'], array_column($accounts, 'name'));
        $this->assertSame(['id', 'name', 'profile', 'disabled', 'comment', 'service'], array_keys($accounts[0]));
        $this->assertStringNotContainsString('fake-seeded-secret', json_encode($accounts));
        $this->assertSame('manual', $accounts[1]['comment']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function failures(): array
    {
        return [
            'refused' => ['refused', RouterOsException::UNREACHABLE],
            'timeout' => ['timeout', RouterOsException::UNREACHABLE],
            'tls' => ['tls', RouterOsException::TLS],
            'auth' => ['auth', RouterOsException::AUTH],
            'forbidden' => ['forbidden', RouterOsException::AUTH],
            'rejected' => ['rejected', RouterOsException::REJECTED],
            'server' => ['server', RouterOsException::SERVER],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_map_to_the_same_kind_and_name_the_device(string $failure, string $kind): void
    {
        $this->router->down($failure);

        $e = $this->exception(fn () => $this->client->systemResource($this->nas));

        $this->assertSame($kind, $e->kind);
        $this->assertSame($this->nas->id, $e->nasDeviceId);
        $this->assertSame($kind === RouterOsException::UNREACHABLE || $kind === RouterOsException::TLS || $kind === RouterOsException::SERVER, $e->retryable());
    }

    public function test_a_rejection_message_loses_control_characters_and_is_capped_at_255_characters(): void
    {
        $this->router->down('rejected', "line one\r\nline two ".str_repeat('x', 400));

        $message = $this->exception(fn () => $this->client->find($this->nas, 'pppoe', 'alice'))->getMessage();

        $this->assertLessThanOrEqual(255, mb_strlen($message));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $message);
        $this->assertStringContainsString('line one line two', $message);
    }

    public function test_with_the_installation_switch_off_nothing_is_sent_not_even_a_read(): void
    {
        $this->disableRouterOs();

        $e = $this->exception(fn () => $this->client->listAccounts($this->nas, 'pppoe'));

        $this->assertSame(RouterOsException::DISABLED, $e->kind);
        $this->assertSame($this->nas->id, $e->nasDeviceId);
        $this->assertSame([], $this->router->requests());
        $this->assertSame([], $this->router->connections());
    }

    public function test_an_asset_without_a_valid_management_ip_is_refused_before_anything_is_sent(): void
    {
        $this->nas->networkAsset->forceFill(['management_ip' => 'not an ip'])->save();

        $e = $this->exception(fn () => $this->client->systemResource($this->nas->fresh('networkAsset')));

        $this->assertSame(RouterOsException::REJECTED, $e->kind);
        $this->assertSame([], $this->router->requests());
        $this->assertSame([], $this->router->connections());
    }

    #[DataProvider('badIds')]
    public function test_a_record_id_that_is_not_a_routeros_id_never_reaches_the_router(string $id): void
    {
        foreach ([
            fn () => $this->client->update($this->nas, 'pppoe', $id, ['profile' => 'isolir']),
            fn () => $this->client->remove($this->nas, 'pppoe', $id),
            fn () => $this->client->disconnect($this->nas, 'pppoe', $id),
        ] as $call) {
            $this->assertSame(RouterOsException::SERVER, $this->exception($call)->kind);
        }

        $this->assertSame([], $this->router->requests());
        $this->assertSame([], $this->router->commands());
    }

    /** @return array<string, array{0: string}> */
    public static function badIds(): array
    {
        return ['path' => ['*1/../x'], 'no star' => ['1A'], 'empty' => ['']];
    }
}
