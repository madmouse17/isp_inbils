<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    private string $buildDirectory = 'build-app-provider-test';

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(public_path($this->buildDirectory))) {
            mkdir(public_path($this->buildDirectory), 0777, true);
        }

        file_put_contents(
            public_path($this->buildDirectory.'/manifest.json'),
            json_encode([
                'resources/js/app.tsx' => [
                    'file' => 'assets/app-test.js',
                    'src' => 'resources/js/app.tsx',
                    'isEntry' => true,
                    'dynamicImports' => ['resources/js/DynamicTest.tsx'],
                ],
                'resources/js/DynamicTest.tsx' => [
                    'file' => 'assets/dynamic-test.js',
                    'src' => 'resources/js/DynamicTest.tsx',
                    'isDynamicEntry' => true,
                ],
            ], JSON_THROW_ON_ERROR),
        );

        Vite::useHotFile(public_path('hot-app-provider-test'));
        Vite::createAssetPathsUsing(fn (string $path) => '/'.$path);
        Vite::usePrefetchStrategy(null);
        Vite::flush();
    }

    protected function tearDown(): void
    {
        Vite::usePrefetchStrategy(null);
        Vite::createAssetPathsUsing(null);
        Vite::useHotFile(public_path('hot'));
        Vite::flush();

        @unlink(public_path($this->buildDirectory.'/manifest.json'));
        @rmdir(public_path($this->buildDirectory));

        parent::tearDown();
    }

    public function test_app_service_provider_enables_vite_prefetch_by_default(): void
    {
        $this->bootProviderForEnvironment('testing');

        $html = $this->viteHtml();

        $this->assertStringContainsString("window.addEventListener('load'", $html);
        $this->assertStringContainsString('dynamic-test.js', $html);
    }

    public function test_app_service_provider_disables_vite_prefetch_in_e2e(): void
    {
        $this->bootProviderForEnvironment('e2e');

        $html = $this->viteHtml();

        $this->assertStringNotContainsString("window.addEventListener('load'", $html);
        $this->assertStringNotContainsString('dynamic-test.js', $html);
    }

    private function bootProviderForEnvironment(string $environment): void
    {
        $this->app->instance('env', $environment);

        (new AppServiceProvider($this->app))->boot();
    }

    private function viteHtml(): string
    {
        return app(FoundationVite::class)(['resources/js/app.tsx'], $this->buildDirectory)->toHtml();
    }
}
