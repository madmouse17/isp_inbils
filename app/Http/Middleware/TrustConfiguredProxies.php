<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * The framework's TrustProxies with its proxy list read from config/security.php on every request (TRUSTED_PROXIES,
 * none by default). `$middleware->trustProxies(at: env(...))` in bootstrap/app.php cannot do that: .env is not loaded
 * yet when that closure runs, so a value set only in .env would be ignored without a word.
 */
final class TrustConfiguredProxies extends TrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies(): array|string|null
    {
        return parent::proxies() ?: config('security.trusted_proxies') ?: null;
    }
}
