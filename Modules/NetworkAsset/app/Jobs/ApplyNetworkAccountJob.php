<?php

namespace Modules\NetworkAsset\Jobs;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NasDeviceHealth;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\RouterOsException;
use Throwable;

/**
 * Brings one network account's router state in line with the database. Carries ids and, for an administrator's run,
 * an opaque keyed hash (the failed-job reporter logs job properties), never a secret, and runs inside the company's
 * system scope.
 *
 * Unique until processing starts (a burst of changes queues one run) and never processed twice at once.
 * Transport trouble is released with backoff and leaves the account pending; a router that says no (bad
 * profile name, wrong credentials) fails the account at once, because retrying cannot help.
 *
 * `$adoptionApproval` is set only by an HTTP save or resync of a user holding network_asset.nas.manage: taking over a
 * secret that already exists on the router (without our comment) is a router-credential decision. It is a keyed hash
 * of the target the administrator saw (router, service type, username, password), and the run adopts only while the
 * account still has exactly that target: staff without that permission can retarget an account before the job runs.
 * The observer, the sync and the retries of a plain run never adopt.
 */
class ApplyNetworkAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Below the worker timeout (90 s) and retry_after (90 s). */
    public int $timeout = 60;

    public int $tries = 5;

    /** Safety net for a lock whose job never reached the queue. */
    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $companyId, public readonly int $accountId, public readonly ?string $adoptionApproval = null) {}

    /** Queue the run once the surrounding transaction committed (immediately when there is none). */
    public static function dispatchAfterCommit(int $companyId, int $accountId, ?string $adoptionApproval = null): void
    {
        DB::afterCommit(fn () => self::dispatch($companyId, $accountId, $adoptionApproval));
    }

    /** What an administrator approves when saving or resyncing: this router, service type, username and password. */
    public static function adoptionApprovalFor(NetworkAccount $account): string
    {
        return hash_hmac('sha256', json_encode(['adopt', (int) $account->nas_device_id, $account->service_type, $account->username, $account->password], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** An administrator's run is queued next to a plain one, and next to the run approved for another target, instead of being dropped as their duplicate. */
    public function uniqueId(): string
    {
        return $this->lockKey().':'.($this->adoptionApproval ?? 'plain');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->lockKey()))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(NetworkAccountProvisioner $provisioner, NasDeviceHealth $health): void
    {
        CompanyService::runAs($this->companyId, function () use ($provisioner, $health): void {
            $account = NetworkAccount::query()->find($this->accountId);

            if ($account === null) {
                return;
            }

            // The approval is good for the target the administrator saw: after a retarget or a new password the run is a plain one.
            $mayAdopt = $this->adoptionApproval !== null && hash_equals(self::adoptionApprovalFor($account), $this->adoptionApproval);

            try {
                $provisioner->apply($account, $mayAdopt);
            } catch (RouterOsException $e) {
                $this->routerFailed($account, $e, $health);
            }
        });
    }

    public function failed(Throwable $e): void
    {
        CompanyService::runAs($this->companyId, function () use ($e): void {
            $account = NetworkAccount::query()->find($this->accountId);

            if ($account === null) {
                return;
            }

            $known = $e instanceof RouterOsException;

            // Anything that is not a RouterOS error may carry text we have not vetted: only its class is kept.
            $this->record($account, 'failed', $known ? $e->getMessage() : 'Unexpected error ('.class_basename($e).').');

            AuditService::log('network_account', 'provision_failed', [
                'username' => $account->username,
                'nas_device_id' => $account->nas_device_id,
                'error_kind' => $known ? $e->kind : 'unexpected',
            ], $account);
        });
    }

    private function lockKey(): string
    {
        return "{$this->companyId}:{$this->accountId}";
    }

    private function routerFailed(NetworkAccount $account, RouterOsException $e, NasDeviceHealth $health): void
    {
        if ($e->kind === RouterOsException::DISABLED) {
            $this->record($account, 'pending', $e->getMessage());

            return;
        }

        if (! $e->retryable()) {
            $this->fail($e);

            return;
        }

        if ($e->kind === RouterOsException::UNREACHABLE && ($nas = NasDevice::query()->find($e->nasDeviceId ?? $account->nas_device_id)) !== null) {
            $health->failed($nas, $e);
        }

        $this->record($account, 'pending', $e->getMessage());

        if ($this->attempts() >= $this->tries) {
            $this->fail($e);

            return;
        }

        $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1]);
    }

    private function record(NetworkAccount $account, string $status, string $reason): void
    {
        $account->forceFill(['sync_status' => $status, 'last_error' => Str::limit($reason, 255, ''), 'last_attempt_at' => now()])->save();
    }
}
