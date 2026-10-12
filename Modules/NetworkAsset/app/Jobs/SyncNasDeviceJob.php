<?php

namespace Modules\NetworkAsset\Jobs;

use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NasDeviceHealth;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\RouterOsApi;
use Modules\NetworkAsset\Services\RouterOsClients;
use Modules\NetworkAsset\Services\RouterOsException;

/**
 * The 15-minute check of one NAS router: reachability and version, who is online, secrets Inbils does not
 * manage, and the accounts that need another apply (pending, drifted, owing a session drop, or failed for over an hour).
 * It never writes to the router itself, and does nothing at all while the installation switch is off.
 */
class SyncNasDeviceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Reading a large router takes a while; still below the worker timeout (90 s). */
    public int $timeout = 80;

    /** The next scheduled run is the retry. */
    public int $tries = 1;

    public int $uniqueFor = 600;

    /** At most this many stray names are kept for the router page. */
    private const UNMANAGED_NAMES_KEPT = 100;

    /** A failed account is tried again at most this often. */
    private const FAILED_RETRY_MINUTES = 60;

    public function __construct(public readonly int $companyId, public readonly int $nasDeviceId) {}

    public function uniqueId(): string
    {
        return "sync:{$this->companyId}:{$this->nasDeviceId}";
    }

    public function handle(RouterOsClients $clients, NasDeviceHealth $health, NetworkAccountProvisioner $provisioner): void
    {
        if (! config('services.routeros.enabled')) {
            return;
        }

        CompanyService::runAs($this->companyId, function () use ($clients, $health, $provisioner): void {
            $nas = NasDevice::query()->find($this->nasDeviceId);

            if ($nas === null) {
                return;
            }

            $client = $clients->for($nas);

            try {
                $resource = $client->systemResource($nas);
            } catch (RouterOsException $e) {
                $health->failed($nas, $e);

                return;
            }

            $health->reachable($nas, $resource);

            try {
                $this->readAccounts($nas, $client, $provisioner);
            } catch (RouterOsException $e) {
                $health->failed($nas, $e); // the router went away mid-sync
            }
        });
    }

    private function readAccounts(NasDevice $nas, RouterOsApi $client, NetworkAccountProvisioner $provisioner): void
    {
        $accounts = $nas->networkAccounts()->with('subscription.servicePackage.speedProfile')->get();
        $accounts->each(fn (NetworkAccount $account) => $account->setRelation('nasDevice', $nas));

        $unmanaged = [];
        $online = [];
        $offline = [];
        $addressChanges = [];
        $apply = [];

        // PPPoE is always read, so a router with no account yet already shows its strays.
        foreach (array_values(array_unique(['pppoe', ...$accounts->pluck('service_type')->all()])) as $type) {
            $rows = collect($client->listAccounts($nas, $type))->keyBy('name');
            $sessions = collect($client->activeSessions($nas, $type))->keyBy('name');
            $ofType = $accounts->where('service_type', $type);
            $usernames = $ofType->pluck('username')->flip();

            foreach ($rows as $name => $row) {
                if (! $usernames->has($name) && ! str_starts_with((string) $row['comment'], NetworkAccountProvisioner::COMMENT_PREFIX)) {
                    $unmanaged[] = (string) $name;
                }
            }

            foreach ($ofType as $account) {
                $session = $sessions->get($account->username);

                if ($session !== null) {
                    $online[] = $account->id;

                    if ($account->current_address !== $session['address']) {
                        $addressChanges[$account->id] = $session['address'];
                    }
                } elseif ($account->current_address !== null) {
                    $offline[] = $account->id;
                }

                if ($nas->provisioning_enabled && $this->needsApply($account, $rows->get($account->username), $provisioner)) {
                    $apply[] = $account->id;
                }
            }
        }

        $this->storeOnlineState($online, $offline, $addressChanges);

        sort($unmanaged);
        $nas->forceFill([
            'unmanaged_count' => count($unmanaged),
            'unmanaged_usernames' => array_slice($unmanaged, 0, self::UNMANAGED_NAMES_KEPT),
        ])->save();

        foreach ($apply as $accountId) {
            ApplyNetworkAccountJob::dispatch($this->companyId, $accountId);
        }
    }

    /**
     * @param  array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string}|null  $row  the router's record for the username
     */
    private function needsApply(NetworkAccount $account, ?array $row, NetworkAccountProvisioner $provisioner): bool
    {
        // A session drop an earlier run left owed is finished whatever the status or the router's profile says.
        if ($account->pending_disconnect_at !== null) {
            return true;
        }

        if ($account->sync_status === 'pending') {
            return true;
        }

        if ($account->sync_status === 'failed') {
            return $account->last_attempt_at === null || $account->last_attempt_at->lte(now()->subMinutes(self::FAILED_RETRY_MINUTES));
        }

        $profile = $provisioner->desiredProfile($account);

        if ($provisioner->desiredState($account) === NetworkAccountProvisioner::ABSENT) {
            return $row !== null && str_starts_with((string) $row['comment'], NetworkAccountProvisioner::COMMENT_PREFIX);
        }

        // A package without a router profile name cannot be applied; the account page shows why.
        return $profile !== null
            && ($account->provisioned_profile !== $profile || $row === null || $row['profile'] !== $profile || $row['disabled']);
    }

    /**
     * @param  list<int>  $online
     * @param  list<int>  $offline
     * @param  array<int, ?string>  $addressChanges
     */
    private function storeOnlineState(array $online, array $offline, array $addressChanges): void
    {
        foreach (array_chunk($online, 500) as $ids) {
            NetworkAccount::query()->whereIn('id', $ids)->update(['last_online_at' => now()]);
        }

        foreach (array_chunk($offline, 500) as $ids) {
            NetworkAccount::query()->whereIn('id', $ids)->update(['current_address' => null]);
        }

        foreach ($addressChanges as $id => $address) {
            NetworkAccount::query()->whereKey($id)->update(['current_address' => $address]);
        }
    }
}
