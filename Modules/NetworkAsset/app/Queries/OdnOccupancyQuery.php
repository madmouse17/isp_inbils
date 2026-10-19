<?php

namespace Modules\NetworkAsset\Queries;

use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use App\Support\SearchOptions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\OptionalProp;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;

/**
 * Read side of the ODN register: how full each ODP is, who sits behind a PON port, which active subscriptions
 * have no recorded drop, and which ports can still take one. Read-only; plain queries filtered on the current
 * company explicitly. A port is occupied when a subscription holds it or it is a splitter's input.
 */
final class OdnOccupancyQuery
{
    /** Splitter chains are at most this deep (OdnService refuses deeper ones), so the walk down is bounded. */
    private const MAX_HOPS = 8;

    /**
     * Every ODP with capacity (its splitter outputs), used, free and percent used.
     *
     * @param  array<string, mixed>  $filters  pop_id, min_percent, search
     */
    public static function odps(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $inner = DB::table('network_assets as a')
            ->leftJoin('locations as l', 'l.id', '=', 'a.location_id')
            ->where('a.company_id', self::companyId())
            ->whereNull('a.deleted_at')
            ->where('a.asset_type', 'odp')
            ->select(['a.public_id as asset_id', 'a.code', 'a.name', 'l.path as location_path'])
            ->selectSub(self::outputCount(false), 'capacity')
            ->selectSub(self::outputCount(true), 'used');

        self::underPop($inner, $filters['pop_id'] ?? null, 'a.location_id');
        self::search($inner, $filters['search'] ?? null, ['a.code', 'a.name']);

        $minPercent = $filters['min_percent'] ?? null;

        $page = DB::query()
            ->fromSub($inner, 'o')
            ->select('o.*')
            ->when(is_numeric($minPercent), fn (Builder $query) => $query->whereRaw('o.capacity > 0 and o.used * 100 >= ? * o.capacity', [(float) $minPercent]))
            ->orderBy('o.code')
            ->paginate($perPage, ['*'], 'odps_page')
            ->withQueryString();

        return $page->through(function (object $row): array {
            $capacity = (int) $row->capacity;
            $used = (int) $row->used;

            return [
                'asset_id' => (string) $row->asset_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'location_path' => $row->location_path === null ? null : (string) $row->location_path,
                'capacity' => $capacity,
                'used' => $used,
                'free' => $capacity - $used,
                'percent' => $capacity > 0 ? round($used / $capacity * 100, 2) : 0.0,
            ];
        });
    }

    /**
     * PON ports of every OLT with the number of subscriptions behind each, through any splitter levels.
     *
     * @param  array<string, mixed>  $filters  pop_id, search
     */
    public static function ponPorts(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = DB::table('odn_ports as p')
            ->join('network_assets as a', 'a.id', '=', 'p.network_asset_id')
            ->leftJoin('locations as l', 'l.id', '=', 'a.location_id')
            ->where('p.company_id', self::companyId())
            ->where('p.kind', OdnPort::KIND_PON)
            ->whereNull('a.deleted_at')
            ->select(['p.id as port_id', 'p.public_id as port_public_id', 'a.public_id as olt_asset_id', 'a.code as olt_code', 'p.number', 'p.label'])
            ->orderBy('a.code')
            ->orderBy('p.number');

        self::underPop($query, $filters['pop_id'] ?? null, 'a.location_id');
        self::search($query, $filters['search'] ?? null, ['a.code', 'a.name', 'p.label']);

        $page = $query->paginate($perPage, ['*'], 'ports_page')->withQueryString();
        $subscribers = self::subscribersBehind($page->getCollection()->map(fn (object $row): int => (int) $row->port_id)->all());

        return $page->through(fn (object $row): array => [
            'port_id' => (string) $row->port_public_id,
            'olt_asset_id' => (string) $row->olt_asset_id,
            'olt_code' => (string) $row->olt_code,
            'number' => (int) $row->number,
            'label' => $row->label === null ? null : (string) $row->label,
            'subscribers' => $subscribers[(int) $row->port_id] ?? 0,
        ]);
    }

    /**
     * Active subscriptions with no recorded drop port: the backfill list. Terminated ones are not listed.
     *
     * @param  array<string, mixed>  $filters  pop_id (serving POP), search
     */
    public static function unmapped(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        return ServiceSubscription::query()
            ->where('status', 'active')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('odn_ports')->whereColumn('odn_ports.subscription_id', 'service_subscriptions.id'))
            ->when(filled($filters['pop_id'] ?? null), fn (EloquentBuilder $query) => $query->where('serving_pop_id', PublicId::resolveOne(Location::class, $filters['pop_id']) ?? 0))
            ->when($search !== '', fn (EloquentBuilder $query) => $query->where(fn (EloquentBuilder $match) => $match
                ->where('code', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn (EloquentBuilder $customer) => $customer->where('name', 'like', '%'.$search.'%'))))
            ->with(['customer:id,name', 'servingPop:id,code'])
            ->orderBy('code')
            ->paginate($perPage, ['*'], 'unmapped_page')
            ->withQueryString()
            ->through(fn (ServiceSubscription $subscription): array => [
                'subscription_id' => $subscription->public_id,
                'code' => $subscription->code,
                'customer_name' => $subscription->customer?->name,
                'serving_pop_code' => $subscription->servingPop?->getAttribute('code'),
                'status' => $subscription->status,
            ]);
    }

    /**
     * Subscriptions behind each port, counting drops on every output below it (PON port, ODC splitter, ODP splitter...).
     *
     * @param  list<int>  $portIds
     * @return array<int, int> port id => subscriber count
     */
    public static function subscribersBehind(array $portIds): array
    {
        $counts = array_fill_keys($portIds, 0);
        $frontier = array_combine($portIds, $portIds);
        $companyId = self::companyId();

        for ($hop = 0; $hop < self::MAX_HOPS && $frontier !== []; $hop++) {
            $rootBySplitter = [];

            foreach (DB::table('odn_splitters')->where('company_id', $companyId)->whereIn('input_port_id', array_keys($frontier))->get(['id', 'input_port_id']) as $splitter) {
                $rootBySplitter[(int) $splitter->id] = $frontier[(int) $splitter->input_port_id];
            }

            if ($rootBySplitter === []) {
                break;
            }

            $frontier = [];

            foreach (DB::table('odn_ports')->where('company_id', $companyId)->whereIn('odn_splitter_id', array_keys($rootBySplitter))->get(['id', 'odn_splitter_id', 'subscription_id']) as $port) {
                $root = $rootBySplitter[(int) $port->odn_splitter_id];

                if ($port->subscription_id !== null) {
                    $counts[$root]++;
                }

                $frontier[(int) $port->id] = $root;
            }
        }

        return $counts;
    }

    /**
     * Free ODP output ports under a POP (the ODP's location descends from the POP by parent_id), for the drop
     * picker at onboarding and on the subscription page, found by typing an ODP code or a splitter label. Full
     * ODPs never appear; without a POP the list stays empty.
     */
    public static function dropPortOptions(Request $request, ?int $servingPopId): OptionalProp
    {
        $underPop = $servingPopId === null || $servingPopId < 1 ? [] : (self::locationIdsUnder($servingPopId) ?? []);

        $base = OdnPort::query()
            ->select('odn_ports.*')
            ->join('odn_splitters as s', 's.id', '=', 'odn_ports.odn_splitter_id')
            ->join('network_assets as a', 'a.id', '=', 'odn_ports.network_asset_id')
            ->where('odn_ports.kind', OdnPort::KIND_SPLITTER_OUT)
            ->whereNull('odn_ports.subscription_id')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('odn_splitters as f')->whereColumn('f.input_port_id', 'odn_ports.id'))
            ->where('a.asset_type', 'odp')
            ->whereNull('a.deleted_at')
            ->whereIn('a.location_id', $underPop)
            ->with(['asset:id,code,location_id', 'asset.location:id,path', 'splitter:id,outputs,label'])
            ->orderBy('a.code')
            ->orderBy('odn_ports.odn_splitter_id')
            ->orderBy('odn_ports.number');

        return SearchOptions::for(
            $request,
            'dropPort',
            $base,
            ['a.code', 's.label'],
            fn (OdnPort $port): array => [
                'value' => $port->public_id,
                'label' => "{$port->asset?->code} · ".self::ratio($port->splitter?->outputs, $port->splitter?->label)." · port {$port->number}",
                'description' => $port->asset?->location?->getAttribute('path'),
            ],
        );
    }

    /** @return list<array{id: string, code: string, name: string}> POPs, for the filter */
    public static function pops(): array
    {
        return Location::query()
            ->where('type', 'pop')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['public_id', 'code', 'name'])
            ->map(fn (Location $pop): array => ['id' => $pop->public_id, 'code' => $pop->code, 'name' => $pop->name])
            ->all();
    }

    /**
     * PON ports of an OLT for its page.
     *
     * @return list<array{id: string, number: int, label: string|null, splitter_id: string|null, subscribers: int}>
     */
    public static function ponPortRows(NetworkAsset $olt): array
    {
        $ports = OdnPort::query()
            ->where('network_asset_id', $olt->id)
            ->where('kind', OdnPort::KIND_PON)
            ->with('feedsSplitter:id,public_id,input_port_id')
            ->orderBy('number')
            ->get();

        $subscribers = self::subscribersBehind(array_map('intval', $ports->modelKeys()));

        return $ports->map(fn (OdnPort $port): array => [
            'id' => $port->public_id,
            'number' => $port->number,
            'label' => $port->label,
            'splitter_id' => $port->feedsSplitter?->public_id,
            'subscribers' => $subscribers[$port->id] ?? 0,
        ])->all();
    }

    /**
     * Splitters of an ODC or ODP with their outputs, who holds each and what each feeds.
     *
     * @return list<array<string, mixed>>
     */
    public static function splitterRows(NetworkAsset $host): array
    {
        return OdnSplitter::query()
            ->where('network_asset_id', $host->id)
            ->with([
                'inputPort.asset:id,code',
                'inputPort.splitter:id,outputs,label',
                'ports' => fn ($ports) => $ports->orderBy('number'),
                'ports.subscription:id,public_id,code,customer_id',
                'ports.subscription.customer:id,name',
                'ports.feedsSplitter:id,public_id,input_port_id',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (OdnSplitter $splitter): array => [
                'id' => $splitter->public_id,
                'label' => $splitter->label,
                'outputs' => $splitter->outputs,
                'input' => $splitter->inputPort === null ? null : [
                    'port_id' => $splitter->inputPort->public_id,
                    'label' => self::label(
                        $splitter->inputPort->kind,
                        (string) $splitter->inputPort->asset?->code,
                        $splitter->inputPort->number,
                        $splitter->inputPort->splitter?->outputs,
                        $splitter->inputPort->splitter?->label,
                    ),
                ],
                'ports' => $splitter->ports->map(fn (OdnPort $port): array => [
                    'id' => $port->public_id,
                    'number' => $port->number,
                    'label' => $port->label,
                    'subscription' => $port->subscription === null ? null : [
                        'id' => $port->subscription->public_id,
                        'code' => $port->subscription->code,
                        'customer_name' => $port->subscription->customer?->name,
                    ],
                    'feeds_splitter_id' => $port->feedsSplitter?->public_id,
                ])->all(),
            ])
            ->all();
    }

    /**
     * Free upstream ports in the company that a splitter's input can be connected to, found by typing an asset
     * code or a splitter label: PON ports first, then ODC outputs, then ODP outputs.
     */
    public static function inputPortOptions(Request $request): OptionalProp
    {
        $base = OdnPort::query()
            ->select('odn_ports.*')
            ->join('network_assets as a', 'a.id', '=', 'odn_ports.network_asset_id')
            ->leftJoin('odn_splitters as s', 's.id', '=', 'odn_ports.odn_splitter_id')
            ->whereNull('odn_ports.subscription_id')
            ->whereNull('a.deleted_at')
            ->whereIn('a.asset_type', ['olt', 'odc', 'odp'])
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('odn_splitters as f')->whereColumn('f.input_port_id', 'odn_ports.id'))
            ->with(['asset:id,code', 'splitter:id,outputs,label'])
            ->orderByRaw("case a.asset_type when 'olt' then 0 when 'odc' then 1 else 2 end")
            ->orderBy('a.code')
            ->orderBy('odn_ports.number');

        return SearchOptions::for(
            $request,
            'inputPort',
            $base,
            ['a.code', 's.label', 'odn_ports.label'],
            fn (OdnPort $port): array => [
                'value' => $port->public_id,
                'label' => self::label($port->kind, (string) $port->asset?->code, $port->number, $port->splitter?->outputs, $port->splitter?->label),
            ],
        );
    }

    /** "OLT-1 · PON 3" or "ODC-2 · 1:4 · keluaran 2" (the splitter label replaces the ratio when it has one). */
    public static function label(string $kind, string $assetCode, int $number, ?int $outputs, ?string $splitterLabel): string
    {
        return "{$assetCode} · ".self::portLabel($kind, $number, $outputs, $splitterLabel);
    }

    /** The port half of label(): "PON 3" or "1:4 · keluaran 2"; the hardware panel shows it next to the asset code. */
    public static function portLabel(string $kind, int $number, ?int $outputs, ?string $splitterLabel): string
    {
        return $kind === OdnPort::KIND_PON ? "PON {$number}" : self::ratio($outputs, $splitterLabel)." · keluaran {$number}";
    }

    /** The splitter label when it has one, else its ratio ("1:8"). */
    public static function ratio(?int $outputs, ?string $splitterLabel): string
    {
        return ($splitterLabel !== null && $splitterLabel !== '') ? $splitterLabel : '1:'.$outputs;
    }

    /** Correlated count of an ODP's splitter outputs; with $usedOnly, those held by a drop or feeding a splitter. */
    private static function outputCount(bool $usedOnly): Builder
    {
        return DB::table('odn_ports as p')
            ->selectRaw('count(*)')
            ->whereColumn('p.network_asset_id', 'a.id')
            ->where('p.kind', OdnPort::KIND_SPLITTER_OUT)
            ->when($usedOnly, fn (Builder $query) => $query->where(fn (Builder $used) => $used
                ->whereNotNull('p.subscription_id')
                ->orWhereExists(fn (Builder $fed) => $fed->selectRaw('1')->from('odn_splitters as s')->whereColumn('s.input_port_id', 'p.id'))));
    }

    /** Keeps rows whose location lies strictly under the POP; an unknown POP keeps nothing. */
    private static function underPop(Builder $query, mixed $popId, string $locationColumn): void
    {
        if (blank($popId)) {
            return;
        }

        $query->whereIn($locationColumn, self::locationIdsUnder(PublicId::resolveOne(Location::class, $popId) ?? 0) ?? []);
    }

    /**
     * Ids of every location strictly under the POP, found by parent_id (never by the path string, which a code
     * containing " > " could fake); null when the POP is not a location of the current company.
     *
     * @return list<int>|null
     */
    private static function locationIdsUnder(int $popId): ?array
    {
        if (! Location::query()->whereKey($popId)->exists()) {
            return null;
        }

        $found = [];
        $level = [$popId];

        // Each level skips ids already found, so a parent_id cycle in bad data ends the walk; no depth cut-off.
        while ($level !== []) {
            $level = array_values(array_diff(
                Location::query()->whereIn('parent_id', $level)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                $found,
                [$popId],
            ));
            $found = [...$found, ...$level];
        }

        return $found;
    }

    /** @param list<string> $columns */
    private static function search(Builder $query, mixed $term, array $columns): void
    {
        $term = is_string($term) ? trim($term) : '';

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $match) use ($columns, $term): void {
            foreach ($columns as $index => $column) {
                $index === 0 ? $match->where($column, 'like', '%'.$term.'%') : $match->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }
}
