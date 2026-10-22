<?php

namespace Tests\Feature;

use Database\Seeders\CustomerDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\E2eFixtureSeeder;
use Database\Seeders\IndonesiaRegionSeeder;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `db:seed` on a developer's machine must not leave `E2E-SUB` and the other Playwright fixtures in the dev database.
 * The E2E reset (`tests/e2e/setup.php`, APP_ENV=e2e) seeds the demo users and the fixtures by name, so it does not depend on this.
 */
class DatabaseSeederEnvironmentTest extends TestCase
{
    /** @return list<class-string> every seeder `DatabaseSeeder` calls in the environment, in order */
    private function seededIn(string $environment): array
    {
        $this->app->instance('env', $environment);

        $called = [];
        $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
        $seeder->shouldReceive('call')->andReturnUsing(function (array|string $classes) use (&$called, $seeder) {
            $called = [...$called, ...(array) $classes];

            return $seeder;
        });
        $seeder->run();

        return $called;
    }

    /** @return array<string, array{string}> */
    public static function developerEnvironments(): array
    {
        return ['local' => ['local'], 'development' => ['development']];
    }

    #[DataProvider('developerEnvironments')]
    public function test_local_and_development_get_the_demo_company_without_the_e2e_fixtures(string $environment): void
    {
        $called = $this->seededIn($environment);

        $this->assertContains(DemoUserSeeder::class, $called);
        $this->assertContains(CustomerDemoSeeder::class, $called);
        $this->assertContains(IndonesiaRegionSeeder::class, $called);
        $this->assertNotContains(E2eFixtureSeeder::class, $called);
    }

    public function test_the_test_suite_still_gets_the_e2e_fixtures_after_the_demo_company(): void
    {
        $called = $this->seededIn('testing');

        $this->assertContains(E2eFixtureSeeder::class, $called);
        $this->assertGreaterThan(array_search(CustomerDemoSeeder::class, $called, true), array_search(E2eFixtureSeeder::class, $called, true));
        $this->assertNotContains(IndonesiaRegionSeeder::class, $called);
    }

    public function test_the_e2e_environment_leaves_the_demo_company_and_fixtures_to_its_setup_script(): void
    {
        $called = $this->seededIn('e2e');

        $this->assertNotContains(DemoUserSeeder::class, $called);
        $this->assertNotContains(E2eFixtureSeeder::class, $called);
        $this->assertNotContains(IndonesiaRegionSeeder::class, $called);
    }
}
