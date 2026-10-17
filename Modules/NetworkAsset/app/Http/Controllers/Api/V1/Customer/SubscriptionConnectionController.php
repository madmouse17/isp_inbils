<?php

namespace Modules\NetworkAsset\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;

class SubscriptionConnectionController extends Controller
{
    /**
     * Whether the PPPoE line of the subscription is up now.
     *
     * Read live from RADIUS. `data` is null while there is nothing true to say: RADIUS is switched off or
     * unreachable, or the subscription has no PPPoE account. `online` carries `since` (when the session started),
     * `offline` carries `last_seen_at` (when the last session ended, null if none has); both are ISO 8601 with an
     * offset. Only the status and those two times leave the server, never an address or a session id.
     *
     * @response array{data: array{status: 'online'|'offline', since: string|null, last_seen_at: string|null}|null}
     */
    public function __invoke(Request $request, string $id, RadiusDirectory $radius): JsonResponse
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);

        abort_unless($customer instanceof Customer, 403);

        $subscription = $customer->subscriptions()->wherePublicId($id)->firstOrFail();
        // A hotspot user comes and goes with the device, so "offline" would tell the customer nothing about the line.
        $username = NetworkAccount::query()->where('subscription_id', $subscription->id)->where('service_type', 'pppoe')->value('username');

        return response()->json(['data' => $username === null ? null : $this->stateOf($radius, $username)]);
    }

    /** @return array{status: string, since: ?string, last_seen_at: ?string}|null */
    private function stateOf(RadiusDirectory $radius, string $username): ?array
    {
        try {
            $session = $radius->openSessions($username)[0] ?? null;
            $lastSeen = $session === null ? $radius->lastSeen($username) : null;
        } catch (RadiusException) {
            return null;
        }

        return [
            'status' => $session === null ? 'offline' : 'online',
            'since' => $this->iso($session['started_at'] ?? null),
            'last_seen_at' => $this->iso($lastSeen),
        ];
    }

    /** radacct keeps plain datetimes in the application's time zone (the staleness check in RadiusDirectory relies on it); the app needs the offset. */
    private function iso(?string $datetime): ?string
    {
        return $datetime === null ? null : Carbon::parse($datetime)->toIso8601String();
    }
}
