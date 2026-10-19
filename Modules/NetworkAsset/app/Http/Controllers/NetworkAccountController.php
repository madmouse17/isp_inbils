<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\IntegrationConfig;
use App\Support\RecordNotFound;
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
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\NetworkAccountService;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * The network account of one subscription. The secret reaches the browser only as Inertia flash data
 * (`network_account.secret`), once, after create or after an audited reveal; never in a prop.
 *
 * Between the redirect and the page the secret waits in the session sealed with the application key, never in
 * clear: show() unseals it into the one response that carries it, and into none that Inertia turns into a
 * version-change 409, because that path reflashes the whole session.
 */
class NetworkAccountController extends Controller
{
    private const SEALED_SECRET = 'network_account.sealed_secret';

    public function show(Request $request, ServiceSubscription $subscription, NetworkAccountProvisioner $provisioner, RadiusDirectory $radius): InertiaResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('network_asset.account.view');

        $this->unsealSecretFlash($request);

        $subscription->load(['customer:id,public_id,name,code', 'packageVersion.speedProfile', 'servingPop:id,public_id,name']);
        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->first();
        $speedProfile = $subscription->packageVersion?->speedProfile;
        [$session, $usage] = $account === null ? [null, null] : $this->liveState($radius, $account);

        return Inertia::render('Admin/NetworkAccounts/Show', [
            'subscription' => [
                'id' => $subscription->public_id,
                'code' => $subscription->code,
                'status' => $subscription->status,
                'blocked_at' => $subscription->blocked_at?->toIso8601String(),
                'customer' => $subscription->customer === null ? null : ['id' => $subscription->customer->public_id, 'name' => $subscription->customer->name, 'code' => $subscription->customer->code],
                'speed_profile' => $speedProfile === null ? null : ['name' => $speedProfile->getAttribute('name'), 'radius_profile_name' => $speedProfile->getAttribute('radius_profile_name')],
                'serving_pop' => $subscription->servingPop === null ? null : ['id' => $subscription->servingPop->public_id, 'name' => $subscription->servingPop->getAttribute('name')],
            ],
            'realm' => Realm::fromCode(CompanyService::current()?->code),
            'account' => $account === null ? null : (new NetworkAccountResource($account))->resolve(),
            'session' => $session,
            'usage' => $usage,
            'desiredState' => $provisioner->stateOf($subscription),
            'isolatedBecause' => $provisioner->isolatedBecause($subscription),
            'radiusEnabled' => app(IntegrationConfig::class)->isEnabled('radius'),
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
            $this->sealSecretFlash($generated);
        }

        return redirect()->route('admin.subscriptions.network-account.show', $subscription)->with('success', 'Akun jaringan dibuat.');
    }

    public function update(UpdateNetworkAccountRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);

        NetworkAccountService::update($this->accountOf($subscription), $request->validated());

        return redirect()->route('admin.subscriptions.network-account.show', $subscription)->with('success', 'Akun jaringan diperbarui.');
    }

    public function reveal(ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('network_asset.account.reveal');

        $this->sealSecretFlash(NetworkAccountService::reveal($this->accountOf($subscription)));

        return redirect()->route('admin.subscriptions.network-account.show', $subscription);
    }

    public function resync(ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('network_asset.account.manage');

        NetworkAccountService::resync($this->accountOf($subscription));

        return back()->with('success', 'Sinkron ulang dijadwalkan.');
    }

    private function accountOf(ServiceSubscription $subscription): NetworkAccount
    {
        return NetworkAccount::query()->where('subscription_id', $subscription->id)->firstOrFail();
    }

    /**
     * The open session and this month's volume, read live. Both are null while RADIUS is off or unreachable:
     * the page still renders.
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function liveState(RadiusDirectory $radius, NetworkAccount $account): array
    {
        try {
            $session = $radius->openSessions($account->username)[0] ?? null;
            $usage = $radius->usageSince($account->username, now()->startOfMonth());
        } catch (RadiusException) {
            return [null, null];
        }

        return [
            $session === null ? null : [
                'session_id' => $session['session_id'],
                'nas_ip' => $session['nas_ip'],
                'nas_label' => $this->nasLabel($session['nas_ip']),
                'framed_ip' => $session['framed_ip'],
                'started_at' => $session['started_at'],
                'updated_at' => $session['updated_at'],
            ],
            ['month' => now()->format('Y-m')] + $usage,
        ];
    }

    /** The label of this company's NAS at that address; null when none of its NAS rows owns it. */
    private function nasLabel(string $nasIp): ?string
    {
        $asset = NasDevice::query()->where('nasname', $nasIp)->with('networkAsset:id,code,name')->first()?->networkAsset;

        return $asset === null ? null : $asset->code.' - '.$asset->name;
    }

    /** One redirect only: the sealed value is flash data, so the session drops it after the next request. */
    private function sealSecretFlash(string $secret): void
    {
        session()->flash(self::SEALED_SECRET, Crypt::encryptString($secret));
    }

    /** Inertia flash data of the current response; session()->now() ages it out unless a reflash keeps it. */
    private function unsealSecretFlash(Request $request): void
    {
        $sealed = session(self::SEALED_SECRET);

        if (! is_string($sealed) || $this->inertiaVersionIsStale($request)) {
            return;
        }

        try {
            Inertia::flash('network_account', ['secret' => Crypt::decryptString($sealed)]);
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

    private function ensureSameCompany(ServiceSubscription $subscription): void
    {
        RecordNotFound::abortUnless($subscription->company_id === CompanyService::currentId());
    }
}
