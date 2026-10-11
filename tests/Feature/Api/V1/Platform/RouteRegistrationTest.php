<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Http\Middleware\Api\EnsureApiAppAccess;
use App\Http\Middleware\Api\EnsureApiUserIsActive;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Tests\TestCase;

/**
 * The route table of the mobile API: one prefix, one name space, and the guards in the order the spec fixes.
 */
class RouteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_route_sits_under_v1_and_is_named_api_v1(): void
    {
        $routes = $this->apiRoutes();

        $this->assertNotEmpty($routes, 'the auth, me and devices routes are registered');

        foreach ($routes as $route) {
            $this->assertStringStartsWith('api/v1/', $route->uri());
            $this->assertStringStartsWith('api.v1.', (string) $route->getName(), $route->uri().' needs a name under api.v1.');
        }
    }

    public function test_the_platform_routes_are_registered(): void
    {
        $registered = collect($this->apiRoutes())
            ->flatMap(fn (LaravelRoute $route): array => array_map(fn (string $method): string => $method.' '.$route->uri(), array_diff($route->methods(), ['HEAD'])))
            ->all();

        foreach ([
            'POST api/v1/auth/login',
            'POST api/v1/auth/two-factor',
            'POST api/v1/auth/forgot-password',
            'POST api/v1/auth/token/refresh',
            'POST api/v1/auth/logout',
            'GET api/v1/me',
            'GET api/v1/devices',
            'DELETE api/v1/devices/{id}',
        ] as $expected) {
            $this->assertContains($expected, $registered);
        }
    }

    public function test_the_sanctum_spa_cookie_route_is_not_exposed(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('sanctum.csrf-cookie'));
        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }

    public function test_the_guards_run_in_the_documented_order_ahead_of_route_model_binding(): void
    {
        Route::middleware(['api', 'api.customer'])->prefix('api/v1/_probe')->get('customer', fn () => 'ok');

        $stack = fn (string $uri): array => app('router')->gatherRouteMiddleware(Route::getRoutes()->match(request()->create($uri, 'GET')));

        $this->assertOrder($stack('/api/v1/_probe/customer'), [
            Authenticate::class,
            ThrottleRequests::class,
            EnsureApiUserIsActive::class,
            CheckAbilities::class,
            EnsureApiAppAccess::class,
            EnforceIdempotency::class,
            SubstituteBindings::class,
        ]);
        $this->assertOrder($stack('/api/v1/me'), [
            Authenticate::class,
            ThrottleRequests::class,
            EnsureApiUserIsActive::class,
            EnsureApiAppAccess::class,
            EnforceIdempotency::class,
            SubstituteBindings::class,
        ]);
    }

    public function test_refresh_runs_the_guards_but_not_idempotency(): void
    {
        $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->match(request()->create('/api/v1/auth/token/refresh', 'POST')));

        $this->assertOrder($stack, [Authenticate::class, EnsureApiUserIsActive::class, EnsureApiAppAccess::class, SubstituteBindings::class]);
        $this->assertNull($this->position($stack, EnforceIdempotency::class), 'auth endpoints take no Idempotency-Key');
    }

    /** @return list<LaravelRoute> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $route): bool => $route->uri() === 'api' || str_starts_with($route->uri(), 'api/'),
        ));
    }

    /**
     * @param  list<string>  $stack
     * @param  list<class-string>  $expected  in the order they must run
     */
    private function assertOrder(array $stack, array $expected): void
    {
        $positions = array_map(fn (string $class): ?int => $this->position($stack, $class), $expected);

        foreach ($positions as $index => $position) {
            $this->assertNotNull($position, $expected[$index].' is part of the stack: '.implode(', ', $stack));
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'guard order: '.implode(', ', $stack));
    }

    /** @param  list<string>  $stack */
    private function position(array $stack, string $class): ?int
    {
        foreach ($stack as $index => $middleware) {
            if (explode(':', $middleware)[0] === $class) {
                return $index;
            }
        }

        return null;
    }
}
