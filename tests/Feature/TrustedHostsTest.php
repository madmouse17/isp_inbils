<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustedHostsTest extends TestCase
{
    public function test_trusted_hosts_middleware_is_registered_so_reset_urls_cannot_use_a_spoofed_host(): void
    {
        $this->assertTrue(app(Kernel::class)->hasMiddleware(TrustHosts::class));
    }
}
