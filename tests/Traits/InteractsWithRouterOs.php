<?php

namespace Tests\Traits;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Tests\Support\FakeRouterOs;

/**
 * Fake MikroTik routers for tests: RouterOS REST faked at the HTTP layer, one in-memory router per
 * host and port, and every call to an unknown host refused (stray requests). Credentials and addresses
 * are fake (RFC 5737 documentation range). No test ever reaches a real router.
 *
 *   $this->fakeRouterOs();                              // switch on, Http faked, strays refused
 *   $router = $this->routerFor($nasDevice)->profile('home-20m', 'isolir');
 *   $router->seedAccount('pppoe', 'alice', 'home-20m');
 *   $router->account('pppoe', 'alice');                 // inspect the router state
 *   $router->down('refused');                           // refused|timeout|tls|auth|rejected|server|redirect|garbage
 *   $router->writes();                                  // every PUT/PATCH/DELETE seen
 */
trait InteractsWithRouterOs
{
    /** @var array<string, FakeRouterOs> */
    private array $fakeRouters = [];

    /** Switch the installation on, fake the router API and refuse any other outbound call. Call once per test. */
    protected function fakeRouterOs(): void
    {
        $this->enableRouterOs();
        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) {
            $uri = $request->toPsrRequest()->getUri();
            $router = $this->fakeRouters[$this->routerKey($uri->getHost(), $uri->getPort() ?? 443)] ?? null;

            return $router?->handle($request, $options);
        });
    }

    protected function enableRouterOs(): void
    {
        config(['services.routeros.enabled' => true, 'services.routeros.ca_bundle' => null]);
    }

    protected function disableRouterOs(): void
    {
        config(['services.routeros.enabled' => false]);
    }

    /** The in-memory router at a host (RFC 5737 documentation addresses in tests), created on first use. */
    protected function routerAt(string $host, int $port = 443): FakeRouterOs
    {
        return $this->fakeRouters[$this->routerKey($host, $port)] ??= new FakeRouterOs($host, $port);
    }

    /** The in-memory router behind a NAS device: its asset's management_ip and the device's API port. */
    protected function routerFor(NasDevice $nas): FakeRouterOs
    {
        $host = (string) NetworkAsset::withoutCompany()->findOrFail($nas->network_asset_id)->management_ip;

        return $this->routerAt($host, $nas->api_port);
    }

    private function routerKey(string $host, int $port): string
    {
        return strtolower(trim($host, '[]')).':'.$port;
    }
}
