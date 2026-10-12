<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * docs/api/mobile-v1.openapi.json is the contract the two Flutter apps are written against. It is generated from
 * the routes, requests and resources, so a change to any of them without a fresh export fails here.
 *
 * The export reads column types from the database, which RefreshDatabase migrates. To regenerate, see
 * docs/operations/DEPLOYMENT.md ("Mobile API contract").
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const COMMITTED = 'docs/api/mobile-v1.openapi.json';

    /** The only endpoints that take no bearer token. */
    private const PUBLIC_ROUTES = ['api.v1.auth.login', 'api.v1.auth.two-factor', 'api.v1.auth.forgot-password'];

    public function test_the_committed_export_matches_a_fresh_export(): void
    {
        $fresh = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            $this->assertSame(0, Artisan::call('scramble:export', ['--path' => $fresh]));

            $this->assertSame(
                file_get_contents(base_path(self::COMMITTED)),
                file_get_contents($fresh),
                self::COMMITTED.' is stale: run `php artisan scramble:export` and commit the result.',
            );
        } finally {
            @unlink($fresh);
        }
    }

    public function test_every_api_route_is_documented_and_only_the_public_ones_skip_the_bearer_token(): void
    {
        $document = json_decode(file_get_contents(base_path(self::COMMITTED)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([['http' => []]], $document['security']);
        $this->assertSame('bearer', $document['components']['securitySchemes']['http']['scheme']);

        $expected = [];
        foreach ($this->apiRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $expected[] = $method.' '.$route->uri();
                $operation = $document['paths'][substr('/'.$route->uri(), strlen('/api/v1'))][strtolower($method)] ?? null;

                $this->assertNotNull($operation, $method.' '.$route->uri().' is missing from the document');

                $security = $operation['security'] ?? $document['security'];

                in_array($route->getName(), self::PUBLIC_ROUTES, true)
                    ? $this->assertSame([], $security, $route->uri().' is public')
                    : $this->assertNotSame([], $security, $route->uri().' needs a bearer token');
            }
        }

        $documented = collect($document['paths'])->sum(fn (array $operations): int => count($operations));
        $this->assertCount($documented, $expected, 'the document describes a route that no longer exists');
    }

    /** @return list<LaravelRoute> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'),
        ));
    }
}
