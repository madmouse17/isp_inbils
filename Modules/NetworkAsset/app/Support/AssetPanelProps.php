<?php

namespace Modules\NetworkAsset\Support;

use App\Models\Core\ServiceSubscription;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\OptionalProp;
use Modules\NetworkAsset\Http\Resources\AssetPanelResource;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Queries\AssetPanelQuery;

/**
 * What a page adds to its Inertia props to carry the hardware panel of an asset (design §4.1): the panel itself,
 * deferred so the page never waits for it or for RADIUS, the detail of the port the user opened, and the option
 * lists of the panel's actions. Nothing here for a type without a panel or for a user who cannot see the asset.
 *
 *     return Inertia::render('Admin/NetworkAssets/Show', ['asset' => ..., ...AssetPanelProps::for($request, $asset)]);
 */
final class AssetPanelProps
{
    /** The types that own asset_ports rows, so the types a port can be linked from and to. */
    private const LINKABLE = ['router', 'switch', 'olt', 'onu_ont'];

    /** The subscription statuses that may hold a drop (OdnService::DROP_STATUSES). */
    private const DROP_STATUSES = ['pending', 'active', 'suspended'];

    /** @return array<string, mixed> */
    public static function for(Request $request, NetworkAsset $asset): array
    {
        $user = $request->user();

        if ($user === null || ! in_array($asset->asset_type, PanelLayout::ASSET_TYPES, true) || ! $user->can('view', $asset)) {
            return [];
        }

        $withOdn = $user->can('viewAny', OdnPort::class);
        $editable = $user->can('updatePanel', $asset);

        $props = [
            'panel' => Inertia::defer(fn (): array => (new AssetPanelResource(AssetPanelQuery::for($asset, $withOdn)))->resolve(), 'panel'),
            'portDetail' => Inertia::optional(fn (): ?array => AssetPanelQuery::portDetail($asset, self::text($request, 'port'), $withOdn)),
        ];

        if ($editable && in_array($asset->asset_type, self::LINKABLE, true)) {
            $props['linkAssetOptions'] = self::linkAssetOptions($request, $asset);
            $props['linkPortOptions'] = Inertia::optional(fn (): array => AssetPanelQuery::freePortOptions($asset, $request->query('link_asset')));
        }

        if ($asset->asset_type === 'odp' && $withOdn && $user->can('odn.assign')) {
            $props['dropSubscriptionOptions'] = self::dropSubscriptionOptions($request, $asset);
        }

        if ($editable && $asset->asset_type === 'rack') {
            $props['rackAssetOptions'] = self::rackAssetOptions($request);
        }

        return $props;
    }

    /** Other assets that have ports and a model, found by code or name. */
    private static function linkAssetOptions(Request $request, NetworkAsset $asset): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'linkAsset',
            NetworkAsset::query()->whereNotNull('port_layout')->whereKeyNot($asset->id)->whereIn('asset_type', self::LINKABLE),
            ['code', 'name'],
            self::assetOption(...),
        );
    }

    /** Devices to mount in a rack, found by code or name. */
    private static function rackAssetOptions(Request $request): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'rackAsset',
            NetworkAsset::query()->where('asset_type', '!=', 'rack'),
            ['code', 'name'],
            self::assetOption(...),
        );
    }

    /**
     * Subscriptions the ODP can take a drop for: in service, without a drop yet, and either without a serving POP or
     * with one that lies strictly above the ODP's location (the rule OdnService::assertUnderServingPop enforces),
     * found by code or customer name. The ancestors are one recursive statement bound to ids, cut off by UNION when
     * a parent_id cycle in bad data comes round again.
     */
    private static function dropSubscriptionOptions(Request $request, NetworkAsset $odp): OptionalProp
    {
        $base = ServiceSubscription::query()
            ->select('service_subscriptions.*', 'c.name as customer_name')
            ->join('customers as c', 'c.id', '=', 'service_subscriptions.customer_id')
            ->whereIn('service_subscriptions.status', self::DROP_STATUSES)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('odn_ports as d')->whereColumn('d.subscription_id', 'service_subscriptions.id'))
            ->where(function (Builder $pop) use ($odp): void {
                $pop->whereNull('service_subscriptions.serving_pop_id');

                if ($odp->location_id !== null) {
                    $pop->orWhereRaw(<<<'SQL'
                        service_subscriptions.serving_pop_id IN (
                            WITH RECURSIVE up (id, parent_id) AS (
                                SELECT l.id, l.parent_id FROM locations l WHERE l.id = ? AND l.company_id = ?
                                UNION
                                SELECT p.id, p.parent_id FROM locations p JOIN up ON p.id = up.parent_id AND p.company_id = ?
                            )
                            SELECT parent_id FROM up WHERE parent_id IS NOT NULL
                        )
                        SQL, [$odp->location_id, $odp->company_id, $odp->company_id]);
                }
            });

        return SearchOptions::for(
            $request,
            'dropSubscription',
            $base,
            ['service_subscriptions.code', 'c.name'],
            fn (ServiceSubscription $subscription): array => [
                'value' => $subscription->public_id,
                'label' => "{$subscription->code} · {$subscription->getAttribute('customer_name')}",
            ],
        );
    }

    /** @return array{value: string, label: string, description: string|null} */
    private static function assetOption(NetworkAsset $asset): array
    {
        return [
            'value' => $asset->public_id,
            'label' => "{$asset->code} · {$asset->name}",
            'description' => trim("{$asset->vendor} {$asset->model}") ?: null,
        ];
    }

    /** A query string value that is text: `?port[]=x` arrives as an array and counts as absent. */
    private static function text(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
