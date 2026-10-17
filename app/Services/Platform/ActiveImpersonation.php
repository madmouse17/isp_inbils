<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Carbon;

final readonly class ActiveImpersonation
{
    public function __construct(
        public string $id,
        public PlatformAdmin $admin,
        public User $user,
        public Company $company,
        public string $reason,
        public int $startedAt,
        public string $mode,
    ) {}

    public function expired(): bool
    {
        return $this->remainingSeconds() === 0;
    }

    public function remainingSeconds(): int
    {
        return max(0, ImpersonationService::MAX_SECONDS - (now()->getTimestamp() - $this->startedAt));
    }

    public function writable(): bool
    {
        return $this->mode === ImpersonationService::WRITE;
    }

    /**
     * The tenant shell has no platform route names, so the banner gets the platform URLs ready-made. The confirm page
     * is told which tenant page the operator is on ($page, the request URI of that page), so confirming leads back to it.
     *
     * @return array{admin_name: string, user_name: string, company_name: string, company_code: string, mode: string, expires_at: string, reason: string, write_url: string, read_url: string, stop_url: string, confirm_url: string}
     */
    public function toProp(?string $page = null): array
    {
        $returnTo = ImpersonationService::tenantPage($page);

        return [
            'admin_name' => $this->admin->name,
            'user_name' => $this->user->name,
            'company_name' => $this->company->name,
            'company_code' => $this->company->code,
            'mode' => $this->mode,
            'expires_at' => Carbon::createFromTimestampUTC($this->startedAt + ImpersonationService::MAX_SECONDS)->toIso8601String(),
            'reason' => $this->reason,
            'write_url' => route('platform.impersonation.write'),
            'read_url' => route('platform.impersonation.read'),
            'stop_url' => route('platform.impersonation.stop'),
            'confirm_url' => route('platform.password.confirm', $returnTo === null ? [] : ['return_to' => $returnTo]),
        ];
    }
}
