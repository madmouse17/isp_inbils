<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class VitePrefetchEnvironmentTest extends TestCase
{
    public function test_provider_prefetches_vite_assets_in_testing_environment(): void
    {
        Vite::shouldReceive('prefetch')
            ->once()
            ->with(3);

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_provider_does_not_prefetch_vite_assets_in_e2e_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'e2e');

        try {
            Vite::shouldReceive('prefetch')->never();

            (new AppServiceProvider($this->app))->boot();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
