<?php

namespace App\Services\Core;

use App\Models\Core\CompanyIntegration;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * Which account a company uses at a provider, read once per request or queued job (#[Scoped]: the worker forgets the
 * instance before each job, so a changed row is never served stale). Without a row the provider is off for that
 * company; only mail falls back to the platform sender, and that fallback lives in CompanyMailChannel.
 *
 * Reads go through withoutCompany(), because a webhook or a worker has no ambient company: the caller names it.
 */
#[Scoped]
final class IntegrationConfig
{
    /** @var array<string, ?CompanyIntegration> "{companyId}:{provider}", a missing row cached as null */
    private array $rows = [];

    public function forCompany(int $companyId, string $provider): ?CompanyIntegration
    {
        $key = "{$companyId}:{$provider}";

        if (! array_key_exists($key, $this->rows)) {
            $this->rows[$key] = CompanyIntegration::withoutCompany()->where('company_id', $companyId)->where('provider', $provider)->first();
        }

        return $this->rows[$key];
    }

    /** The row of the company the request, job or command acts in. */
    public function current(string $provider): ?CompanyIntegration
    {
        $companyId = CompanyService::currentId();

        return $companyId === null ? null : $this->forCompany($companyId, $provider);
    }

    public function isEnabled(string $provider, ?int $companyId = null): bool
    {
        $companyId ??= CompanyService::currentId();

        return $companyId !== null && $this->forCompany($companyId, $provider)?->is_enabled === true;
    }

    /** Called by the model after every save and delete, so the next read sees the new row. */
    public function forget(int $companyId, string $provider): void
    {
        unset($this->rows["{$companyId}:{$provider}"]);

        // A RADIUS connection is built from the row, so a changed row must not keep serving the old link.
        if ($provider === 'radius') {
            DB::purge("radius_{$companyId}");
        }
    }
}
