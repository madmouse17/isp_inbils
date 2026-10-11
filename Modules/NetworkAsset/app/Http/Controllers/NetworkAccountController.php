<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Support\Header;
use Modules\NetworkAsset\Http\Requests\StoreNetworkAccountRequest;
use Modules\NetworkAsset\Http\Requests\UpdateNetworkAccountRequest;
use Modules\NetworkAsset\Http\Resources\NetworkAccountResource;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\NetworkAccountService;

/**
 * The network account of one subscription. The password reaches the browser only as Inertia flash data
 * (`network_account.password`), once, after create or after an audited reveal; never in a prop.
 *
 * Between the redirect and the page the password waits in the session sealed with the application key, never in
 * clear: show() unseals it into the one response that carries it, and into none that Inertia turns into a
 * version-change 409, because that path reflashes the whole session.
 */
class NetworkAccountController extends Controller
{
    private const SEALED_PASSWORD = 'network_account.sealed_password';

    public function show(Request $request, ServiceSubscription $subscription, NetworkAccountProvisioner $provisioner): InertiaResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('network_asset.account.view');

        $this->unsealPasswordFlash($request);

        $subscription->load(['customer:id,name', 'servicePackage.speedProfile', 'servingPop:id,name']);
        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->with('nasDevice.networkAsset')->first();
        $speedProfile = $subscription->servicePackage?->speedProfile;

        return Inertia::render('Admin/NetworkAccounts/Show', [
            'subscription' => [
                'id' => $subscription->id,
                'code' => $subscription->code,
                'status' => $subscription->status,
                'customer' => $subscription->customer === null ? null : ['id' => $subscription->customer->id, 'name' => $subscription->customer->name],
                'speed_profile' => $speedProfile === null ? null : ['name' => $speedProfile->getAttribute('name'), 'radius_profile_name' => $speedProfile->getAttribute('radius_profile_name')],
                'serving_pop' => $subscription->servingPop === null ? null : ['id' => $subscription->servingPop->getKey(), 'name' => $subscription->servingPop->getAttribute('name')],
            ],
            'account' => $account === null ? null : (new NetworkAccountResource($account))->resolve(),
            'desiredState' => $provisioner->stateOf($subscription),
            'nasOptions' => $this->nasOptions($subscription),
            'installEnabled' => (bool) config('services.routeros.enabled'),
            'can' => [
                'manage' => $request->user()?->can('network_asset.account.manage') ?? false,
                'reveal' => $request->user()?->can('network_asset.account.reveal') ?? false,
            ],
        ]);
    }

    public function store(StoreNetworkAccountRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);

        [, $generated] = NetworkAccountService::create($subscription, $request->validated());

        if ($generated !== null) {
            $this->sealPasswordFlash($generated);
        }

        return redirect()->route('admin.subscriptions.network-account.show', $subscription)->with('success', 'Network account created.');
    }

    public function update(UpdateNetworkAccountRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);

        NetworkAccountService::update($this->accountOf($subscription), $request->validated());

        return redirect()->route('admin.subscriptions.network-account.show', $subscription)->with('success', 'Network account updated.');
    }

    public function reveal(ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('network_asset.account.reveal');

        $this->sealPasswordFlash(NetworkAccountService::reveal($this->accountOf($subscription)));

        return redirect()->route('admin.subscriptions.network-account.show', $subscription);
    }

    public function resync(ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('network_asset.account.manage');

        $account = $this->accountOf($subscription);
        ApplyNetworkAccountJob::dispatch((int) $account->company_id, $account->id, NetworkAccountService::adoptionApproval($account));

        return back()->with('success', 'Resync queued.');
    }

    private function accountOf(ServiceSubscription $subscription): NetworkAccount
    {
        return NetworkAccount::query()->where('subscription_id', $subscription->id)->firstOrFail();
    }

    /** One redirect only: the sealed value is flash data, so the session drops it after the next request. */
    private function sealPasswordFlash(string $password): void
    {
        session()->flash(self::SEALED_PASSWORD, Crypt::encryptString($password));
    }

    /** Inertia flash data of the current response; session()->now() ages it out unless a reflash keeps it. */
    private function unsealPasswordFlash(Request $request): void
    {
        $sealed = session(self::SEALED_PASSWORD);

        if (! is_string($sealed) || $this->inertiaVersionIsStale($request)) {
            return;
        }

        try {
            Inertia::flash('network_account', ['password' => Crypt::decryptString($sealed)]);
        } catch (DecryptException) {
            // The application key changed between the redirect and this page: nothing to show, reveal still works.
        }
    }

    /** The check Inertia's middleware makes after the controller ran: a stale GET gets a 409 and its session is reflashed. */
    private function inertiaVersionIsStale(Request $request): bool
    {
        return (bool) $request->header(Header::INERTIA)
            && $request->method() === 'GET'
            && $request->header(Header::VERSION, '') !== Inertia::getVersion();
    }

    /** @return list<array{id: int, label: string, suggested: bool}> the router at the subscription's POP is suggested */
    private function nasOptions(ServiceSubscription $subscription): array
    {
        return NasDevice::query()
            ->with('networkAsset:id,code,name,location_id')
            ->get()
            ->sortBy(fn (NasDevice $nas): string => (string) $nas->networkAsset?->code)
            ->map(fn (NasDevice $nas): array => [
                'id' => $nas->id,
                'label' => $nas->networkAsset === null ? '#'.$nas->id : $nas->networkAsset->code.' - '.$nas->networkAsset->name,
                'suggested' => $subscription->serving_pop_id !== null && $nas->networkAsset?->location_id === $subscription->serving_pop_id,
            ])
            ->values()
            ->all();
    }

    private function ensureSameCompany(ServiceSubscription $subscription): void
    {
        abort_unless($subscription->company_id === CompanyService::currentId(), 404);
    }
}
