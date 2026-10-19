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
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Queries\AssetPanelQuery;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\Radius\DisconnectPendingException;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Throwable;

/**
 * Brings one network account's RADIUS state in line with the database. Carries ids only (the failed-job reporter
 * logs job properties) and runs inside the company's system scope.
 *
 * Unique until processing starts (a burst of changes queues one run) and never processed twice at once.
 * A RADIUS database error or a Disconnect nobody answered is released with backoff and leaves the account pending;
 * input RADIUS cannot store (no profile name, invalid realm) fails the account at once, because retrying cannot help.
 */
class ApplyNetworkAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Below the worker timeout (90 s) and retry_after (180 s). */
    public int $timeout = 60;

    public int $tries = 5;

    /** Safety net for a lock whose job never reached the queue. */
    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $companyId, public readonly int $accountId) {}

    /** Queue the run once the surrounding transaction committed (immediately when there is none). */
    public static function dispatchAfterCommit(int $companyId, int $accountId): void
    {
        DB::afterCommit(fn () => self::dispatch($companyId, $accountId));
    }

    public function uniqueId(): string
    {
        return $this->lockKey();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->lockKey()))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(NetworkAccountProvisioner $provisioner): void
    {
        CompanyService::runAs($this->companyId, function () use ($provisioner): void {
            $account = NetworkAccount::query()->find($this->accountId);

            if ($account === null) {
                return;
            }

            try {
                $provisioner->apply($account);
                // Block, unblock, provision and resync all end here, after the sessions were cut: the panels' online sets are stale now.
                AssetPanelQuery::forgetOnline($this->companyId);
            } catch (RadiusException $e) {
                match ($e->kind) {
                    RadiusException::DISABLED => $this->record($account, 'pending', $e->getMessage()),
                    RadiusException::REJECTED => $this->fail($e),
                    default => $this->retryLater($account, $e),
                };
            } catch (DisconnectPendingException $e) {
                $this->retryLater($account, $e);
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

            $known = $e instanceof RadiusException || $e instanceof DisconnectPendingException;

            // Anything else may carry text we have not vetted: only its class is kept.
            $this->record($account, 'failed', $known ? $e->getMessage() : 'Unexpected error ('.class_basename($e).').');

            AuditService::log('network_account', 'provision_failed', [
                'username' => $account->username,
                'error_kind' => $e instanceof RadiusException ? $e->kind : ($known ? 'disconnect' : 'unexpected'),
            ], $account);
        });
    }

    private function lockKey(): string
    {
        return "{$this->companyId}:{$this->accountId}";
    }

    private function retryLater(NetworkAccount $account, Throwable $e): void
    {
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
