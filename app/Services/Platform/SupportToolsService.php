<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Queries\Platform\PlatformTenantListQuery;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportToolsService
{
    public const WINDOW_DAYS = 30;

    private const INVITATION_ATTEMPTS = 6;

    private const INVITATION_DECAY_SECONDS = 60;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly PlatformAuditService $audit,
    ) {}

    public function isHomedIn(Company $root, User $user): bool
    {
        return Company::query()
            ->whereKey($user->company_id)
            ->where(fn ($companies) => $companies->whereKey($root->id)->orWhere('parent_company_id', $root->id))
            ->exists();
    }

    public function resetTwoFactor(PlatformAdmin $actor, User $user): void
    {
        $this->twoFactor->adminReset($actor, $user);
        $this->record($actor, $user, 'user.two_factor_reset');
    }

    public function resendInvitation(PlatformAdmin $actor, User $user): void
    {
        if (! $user->is_active || $user->last_login_at !== null || ! $user->hasRole('admin')) {
            throw ValidationException::withMessages([
                'invitation' => 'Undangan hanya dapat dikirim kepada admin aktif yang belum pernah masuk.',
            ]);
        }

        $key = 'platform-invitation|'.$user->id;
        $attempts = RateLimiter::hit($key, self::INVITATION_DECAY_SECONDS);

        if ($attempts > self::INVITATION_ATTEMPTS) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                'Terlalu banyak pengiriman undangan. Coba lagi nanti.',
                headers: ['Retry-After' => $seconds],
            );
        }

        $user->notify(new TenantAdminInvitation((int) $user->company_id));
        $this->record($actor, $user, 'user.invitation_resent');
    }

    public function unlockLogin(PlatformAdmin $actor, User $user): void
    {
        RateLimiter::clear('two-factor-user|'.$user->id);
        $this->record($actor, $user, 'user.login_unlocked');
    }

    /** @return LengthAwarePaginator<int, array{id: int, uuid: mixed, queue: mixed, job: mixed, exception: string, company: array{id: int, code: string}, failed_at: string}> */
    public function failedJobs(Company $root, int $page, int $perPage): LengthAwarePaginator
    {
        $companies = $this->companies($root);
        $companyIds = $companies->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $matches = [];

        foreach (DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->select(['id', 'payload'])
            ->cursor() as $row) {
            $companyId = PlatformTenantListQuery::companyOfFailedJob((string) $row->payload);

            if ($companyId !== null && in_array($companyId, $companyIds, true)) {
                $matches[(int) $row->id] = $companyId;
            }
        }

        $ids = array_slice(array_keys($matches), ($page - 1) * $perPage, $perPage);
        $rows = $ids === []
            ? collect()
            : DB::table('failed_jobs')
                ->whereIn('id', $ids)
                ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->keyBy('id');

        $items = collect($ids)->map(function (int $id) use ($rows, $matches, $companies): array {
            $row = $rows[$id];
            $companyId = $matches[$id];

            return [
                'id' => $id,
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'job' => json_decode((string) $row->payload, true)['displayName'] ?? null,
                'exception' => Str::limit(Str::before((string) $row->exception, "\n"), 200),
                'company' => ['id' => $companyId, 'code' => $companies[$companyId]->code],
                'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
            ];
        });

        /** @var LengthAwarePaginator<int, array{id: int, uuid: mixed, queue: mixed, job: mixed, exception: string, company: array{id: int, code: string}, failed_at: string}> $result */
        $result = new LengthAwarePaginator($items, count($matches), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);

        return $result;
    }

    /** @return LengthAwarePaginator<int, array{id: int, event: mixed, template: mixed, error_code: mixed, recipient_last4: mixed, company: array{id: int, code: string}, failed_at: string}> */
    public function failedNotifications(Company $root, int $page, int $perPage): LengthAwarePaginator
    {
        $companies = $this->companies($root);

        $result = DB::table('whatsapp_messages')
            ->whereIn('company_id', $companies->keys())
            ->where('status', 'failed')
            ->where('failed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->select(['id', 'company_id', 'event', 'template', 'error_code', 'recipient_last4', 'failed_at'])
            ->paginate($perPage, page: $page)
            ->withQueryString();
        /** @var Collection<int, object{id: mixed, company_id: mixed, event: mixed, template: mixed, error_code: mixed, recipient_last4: mixed, failed_at: mixed}> $rows */
        $rows = collect($result->items());
        $items = $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'event' => $row->event,
            'template' => $row->template,
            'error_code' => $row->error_code,
            'recipient_last4' => $row->recipient_last4,
            'company' => ['id' => (int) $row->company_id, 'code' => $companies[$row->company_id]->code],
            'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
        ]);

        /** @var LengthAwarePaginator<int, array{id: int, event: mixed, template: mixed, error_code: mixed, recipient_last4: mixed, company: array{id: int, code: string}, failed_at: string}> $mapped */
        $mapped = new LengthAwarePaginator($items, $result->total(), $result->perPage(), $result->currentPage(), [
            'path' => $result->path(),
            'query' => request()->query(),
        ]);

        return $mapped;
    }

    /** @return Collection<int, Company> */
    private function companies(Company $root): Collection
    {
        return Company::query()
            ->whereKey($root->id)
            ->orWhere('parent_company_id', $root->id)
            ->orderBy('id')
            ->get(['id', 'code'])
            ->keyBy('id');
    }

    private function record(PlatformAdmin $actor, User $user, string $action): void
    {
        $properties = ['user_id' => $user->id];
        $this->audit->platform($action, $properties, $user, $actor);
        $this->audit->support($action, $properties, $user, $actor);
    }
}
