<?php

namespace Modules\NetworkAsset\Queries;

use App\Services\Core\CompanyService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Modules\NetworkAsset\Support\PanelLayout;
use stdClass;

/**
 * Read side of the hardware panel (spec .scratch/hardware-panel-ui, design §4.2): the facts one panel draws, the
 * detail of one port and the fibre path of one subscription. Plain queries filtered on the current company
 * explicitly, a constant number of statements however many ports the device has, and no write.
 *
 * for() returns facts with every flag spelled out; AssetPanelResource turns them into the wire payload.
 * Who is online comes from one radacct query for all drop usernames of the panel, reused for 15 seconds.
 */
final class AssetPanelQuery
{
    /** The panel says "diperbarui ≤ 15 dtk": one asset's online set is reused for this long. */
    private const ONLINE_TTL = 15;

    /** Splitters below a PON port, as deep as OdnService lets a chain grow (its MAX_HOPS). */
    private const MAX_HOPS = 8;

    private const NO_STATE = ['occupied' => false, 'online' => false, 'isolir' => false, 'admin_down' => false, 'unregistered' => false];

    /** Columns of the ODN port statement: the port, its splitter, what feeds that splitter, what the port feeds, and its drop. */
    private const ODN_COLUMNS = [
        'o.id', 'o.public_id', 'o.kind', 'o.number', 'o.label', 'o.odn_splitter_id', 'o.subscription_id',
        's.outputs as splitter_outputs', 's.label as splitter_label', 's.input_port_id',
        'ip.kind as up_kind', 'ip.number as up_number', 'ia.code as up_asset', 'ips.outputs as up_outputs', 'ips.label as up_label',
        'f.id as fed_splitter_id', 'f.outputs as fed_outputs', 'f.label as fed_label', 'fa.public_id as fed_asset_id', 'fa.code as fed_asset',
        'sub.public_id as sub_id', 'sub.code as sub_code', 'sub.status as sub_status', 'sub.blocked_at as sub_blocked_at',
        'c.public_id as customer_id', 'c.name as customer_name', 'na.username',
    ];

    /**
     * Facts for one panel. At most 4 SQL statements and 1 cached radacct query, whatever the port count. Without
     * $withOdn (the odn.view permission) the ODN-backed parts (PON ports, splitters, the drop of an ONT) are left out.
     *
     * @return array{asset: array{id: string, code: string, type: string}, template: ?array<string, mixed>, groups: list<array<string, mixed>>, online: array{known: bool, reason: ?string}, picker: ?array<string, mixed>, rack: ?array<string, mixed>, odn_hidden: bool}
     */
    public static function for(NetworkAsset $asset, bool $withOdn): array
    {
        $facts = [
            'asset' => ['id' => $asset->public_id, 'code' => $asset->code, 'type' => $asset->asset_type],
            'template' => null,
            'groups' => [],
            'online' => ['known' => true, 'reason' => null],
            'picker' => null,
            'rack' => null,
            'odn_hidden' => false,
        ];

        return match ($asset->asset_type) {
            'rack' => [...$facts, 'rack' => self::rack($asset)],
            'odc', 'odp' => self::enclosure($asset, $withOdn, $facts),
            default => self::device($asset, $withOdn, $facts),
        };
    }

    /**
     * One port of this asset, by public id: an asset_ports row, or (with $withOdn) an odn_ports row. Customer and
     * subscription appear only here. Null when the asset has no such port.
     *
     * @return array<string, mixed>|null
     */
    public static function portDetail(NetworkAsset $asset, string $portPublicId, bool $withOdn): ?array
    {
        if (! Str::isUlid($portPublicId)) {
            return null;
        }

        $companyId = self::companyId();
        $publicId = Str::lower($portPublicId);

        // An ODC or ODP has no asset_ports rows: every port of it is in the ODN register.
        if (! in_array($asset->asset_type, ['odc', 'odp'], true)) {
            $port = DB::table('asset_ports as p')
                ->leftJoin('asset_ports as lp', fn ($join) => $join->on('lp.id', '=', 'p.linked_port_id')->on('lp.company_id', '=', 'p.company_id'))
                ->leftJoin('network_assets as la', 'la.id', '=', 'lp.network_asset_id')
                ->where('p.company_id', $companyId)
                ->where('p.network_asset_id', $asset->id)
                ->where('p.public_id', $publicId)
                ->first(['p.public_id', 'p.group_key', 'p.number', 'p.name', 'p.label', 'p.note', 'p.admin_down', 'la.public_id as link_asset_id', 'la.code as link_asset', 'la.name as link_asset_name', 'lp.public_id as link_port_id', 'lp.name as link_port']);

            if ($port !== null) {
                return self::assetPortDetail($asset, $port, $withOdn, $companyId);
            }
        }

        if (! $withOdn) {
            return null;
        }

        $odn = self::odnQuery($companyId)
            ->leftJoin('service_packages as pk', 'pk.id', '=', 'sub.service_package_id')
            ->where('o.network_asset_id', $asset->id)
            ->where('o.public_id', $publicId)
            ->first([...self::ODN_COLUMNS, 'pk.name as package_name']);

        return $odn === null ? null : self::odnPortDetail($asset, $odn, $companyId);
    }

    /**
     * The fibre path of one subscription of the current company, OLT first and the customer last, each hop with its own
     * layout and the one port that is lit, so a hop costs a constant payload. Null when the subscription is not found;
     * `hops` is empty when it has no recorded drop. At most 3 SQL statements and 1 cached radacct query.
     *
     * @return array<string, mixed>|null
     */
    public static function tracePath(string $subscriptionPublicId): ?array
    {
        if (! Str::isUlid($subscriptionPublicId)) {
            return null;
        }

        $companyId = self::companyId();

        $subscription = DB::table('service_subscriptions as sub')
            ->join('customers as c', 'c.id', '=', 'sub.customer_id')
            ->leftJoin('network_accounts as na', 'na.subscription_id', '=', 'sub.id')
            ->where('sub.company_id', $companyId)
            ->where('sub.public_id', Str::lower($subscriptionPublicId))
            ->whereNull('sub.deleted_at')
            ->first(['sub.id', 'sub.public_id', 'sub.code', 'sub.status', 'sub.blocked_at', 'sub.ont_asset_id', 'c.name as customer_name', 'na.username']);

        if ($subscription === null) {
            return null;
        }

        $path = [
            'subscription' => ['id' => $subscription->public_id, 'code' => $subscription->code, 'customer' => $subscription->customer_name],
            'state' => self::flags([]),
            'online' => ['known' => true],
            'hops' => [],
        ];

        $rows = self::pathRows($companyId, (int) $subscription->id);

        if ($rows->isEmpty()) {
            return $path;
        }

        $online = self::online($companyId, "sub:{$subscription->id}", [$subscription->username]);
        $username = self::lower($subscription->username);

        return [
            ...$path,
            'state' => self::flags([
                'occupied' => true,
                'online' => $username !== null && isset($online['set'][$username]),
                'isolir' => self::isolated($subscription->status, $subscription->blocked_at),
            ]),
            'online' => array_filter(['known' => $online['known'], 'reason' => $online['reason']], fn (mixed $value): bool => $value !== null),
            'hops' => [...self::pathHops($rows), self::customerHop($companyId, $subscription)],
        ];
    }

    /**
     * Free ports of another asset of the company, to link a port of $source to. Empty for an unknown, foreign, deleted
     * or the same asset.
     *
     * @return list<array{value: string, label: string, description: null}>
     */
    public static function freePortOptions(NetworkAsset $source, mixed $targetAssetPublicId): array
    {
        if (! is_string($targetAssetPublicId) || ! Str::isUlid($targetAssetPublicId)) {
            return [];
        }

        $companyId = self::companyId();

        return DB::table('asset_ports as p')
            ->join('network_assets as a', 'a.id', '=', 'p.network_asset_id')
            ->where('p.company_id', $companyId)
            ->where('a.company_id', $companyId)
            ->where('a.public_id', Str::lower($targetAssetPublicId))
            ->where('a.id', '!=', $source->id)
            ->whereNull('a.deleted_at')
            ->whereNull('p.linked_port_id')
            ->orderBy('p.group_key')
            ->orderBy('p.number')
            ->limit(PanelLayout::MAX_PORTS)
            ->get(['p.public_id', 'p.name', 'p.label'])
            ->map(fn (object $port): array => [
                'value' => (string) $port->public_id,
                'label' => filled($port->label) ? "{$port->name} · {$port->label}" : (string) $port->name,
                'description' => null,
            ])
            ->all();
    }

    /** Throws away every panel of the company that holds an online set: the next read asks RADIUS again. */
    public static function forgetOnline(int $companyId): void
    {
        // The HomeFeedCache pattern: bumping a version needs no cache tags, and the database store has none.
        DB::afterCommit(fn () => Cache::forever(self::versionKey($companyId), (string) Str::uuid()));
    }

    /**
     * The wire form of a port state: only the flags that are true, always an object.
     *
     * @param  array<string, bool>  $state
     */
    public static function flags(array $state): array|stdClass
    {
        $set = array_filter($state);

        return $set === [] ? new stdClass() : $set;
    }

    /**
     * Router, switch, OLT and ONT: drawn from the layout snapshot of the asset. Ports of the register come from
     * asset_ports; PON ports of an OLT and the drop of an ONT come from the ODN register. The catalogue rows of the
     * type go along as the picker also once the unit has a model: they are the choices of "Ganti model".
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function device(NetworkAsset $asset, bool $withOdn, array $facts): array
    {
        $layout = $asset->port_layout;
        $catalogue = self::catalogue($asset);
        $picker = self::picker($asset, $catalogue);

        if (! is_array($layout)) {
            return [...$facts, 'picker' => $picker];
        }

        $companyId = self::companyId();
        $isOlt = $asset->asset_type === 'olt';
        $isOnt = $asset->asset_type === 'onu_ont';
        $registered = self::assetPortRows($asset, $companyId)->keyBy(fn (object $row): string => "{$row->group_key}:{$row->number}");
        $odn = $withOdn && $isOlt ? self::odnRows($asset, $companyId) : collect();
        $drop = $withOdn && $isOnt ? self::ontDrop($asset, $companyId) : null;
        $behind = self::behind($companyId, $odn);
        $online = self::online($companyId, "asset:{$asset->id}", self::usernames($odn, $behind, $drop));
        $slots = $isOlt ? PanelLayout::ponSlots($layout) : [];
        $hidden = ! $withOdn && ($isOnt || $slots !== []);

        // Without odn.view the PON blocks leave the drawing together with their groups, so every drawn block has a group.
        $drawn = $isOlt && ! $withOdn ? self::withoutPon($layout) : $layout;
        $groups = self::groupSkeletons($drawn);

        foreach (PanelLayout::assetPortRows($drawn) as $slot) {
            $groups[$slot['group_key']]['ports'][] = self::assetPort($slot, $registered->get("{$slot['group_key']}:{$slot['number']}"), $drop, $online);
        }

        if ($isOlt && $withOdn) {
            $groups = self::ponGroups($groups, $slots, $odn, $behind, $online);
            $drawn = self::withExtraPonRows($drawn, $groups);
        }

        return [
            ...$facts,
            'template' => self::template($asset, $drawn, $catalogue),
            'groups' => array_values($groups),
            'online' => ['known' => $online['known'], 'reason' => $online['reason']],
            'picker' => $picker,
            'odn_hidden' => $hidden,
        ];
    }

    /**
     * ODC and ODP: no layout of their own, the splitters are the panel. One chassis row per splitter holds its IN
     * pseudo-port and its outputs.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function enclosure(NetworkAsset $asset, bool $withOdn, array $facts): array
    {
        if (! $withOdn) {
            return [...$facts, 'odn_hidden' => true];
        }

        $companyId = self::companyId();
        $rows = self::odnRows($asset, $companyId);

        if ($rows->isEmpty()) {
            return [...$facts, 'picker' => self::picker($asset)];
        }

        $behind = self::behind($companyId, $rows);
        $online = self::online($companyId, "asset:{$asset->id}", self::usernames($rows, $behind));
        $groups = [];
        $layoutRows = [];

        foreach ($rows->sortBy([['odn_splitter_id', 'asc'], ['number', 'asc']])->groupBy('odn_splitter_id')->values() as $index => $outputs) {
            $n = $index + 1;
            $first = $outputs->first();
            $ratio = OdnOccupancyQuery::ratio((int) $first->splitter_outputs, $first->splitter_label);
            $layoutRows[] = self::splitterRow($n, $outputs->count(), $ratio);

            $groups[] = [
                'key' => "in{$n}",
                'kind' => 'sc',
                'role' => 'splitter_in',
                'label' => 'Masuk',
                'ports' => [self::port(
                    id: null,
                    number: 1,
                    name: 'IN',
                    label: null,
                    state: ['occupied' => $first->input_port_id !== null],
                    link: $first->input_port_id === null ? null : ['asset' => (string) $first->up_asset, 'port' => OdnOccupancyQuery::portLabel($first->up_kind, (int) $first->up_number, $first->up_outputs === null ? null : (int) $first->up_outputs, $first->up_label)],
                )],
            ];

            $groups[] = [
                'key' => "s{$n}",
                'kind' => 'sc',
                'role' => 'splitter_out',
                'label' => "Splitter {$ratio}",
                'ports' => $outputs->map(fn (object $row): array => self::odnPort($row, (int) $row->number, (string) $row->number, $behind, $online))->all(),
            ];
        }

        return [
            ...$facts,
            'template' => self::template($asset, ['rows' => $layoutRows]),
            'groups' => $groups,
            'online' => ['known' => $online['known'], 'reason' => $online['reason']],
        ];
    }

    /** @return array{height_u: ?int, items: list<array<string, mixed>>} */
    private static function rack(NetworkAsset $asset): array
    {
        $items = DB::table('network_assets')
            ->where('company_id', self::companyId())
            ->where('rack_asset_id', $asset->id)
            ->whereNull('deleted_at')
            ->orderByDesc('rack_unit')
            ->get(['public_id', 'code', 'name', 'asset_type', 'rack_unit', 'height_u', 'port_layout']);

        return [
            'height_u' => $asset->height_u,
            'items' => $items->map(fn (object $item): array => [
                'id' => (string) $item->public_id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'type' => (string) $item->asset_type,
                'unit' => (int) $item->rack_unit,
                'height' => (int) ($item->height_u ?? 1),
                'layout' => self::decode($item->port_layout),
            ])->all(),
        ];
    }

    /**
     * The catalogue rows of the asset's type, and the row its model was copied from when that row is of another type
     * (the asset changed type after the model was applied; its snapshot stays until "Ganti model"). One statement
     * serves the picker and the vendor and model of the template. Rows have key, vendor, model, height_u, asset_type.
     *
     * @return Collection<int, stdClass>
     */
    private static function catalogue(NetworkAsset $asset): Collection
    {
        return DB::table('port_templates')
            ->where(function (Builder $rows) use ($asset): void {
                $rows->where('asset_type', $asset->asset_type);

                if ($asset->template_key !== null) {
                    $rows->orWhere('key', $asset->template_key);
                }
            })
            ->orderBy('vendor')
            ->orderBy('model')
            ->get(['key', 'vendor', 'model', 'height_u', 'asset_type']);
    }

    /**
     * The catalogue rows the asset can take as its model, with the one its vendor and model text names. A unit that
     * has a model gets them too, for "Ganti model". Null while the type has no catalogue row (a rack, an ODC).
     *
     * @param  Collection<int, stdClass>|null  $catalogue  catalogue() when the caller has it already
     * @return array{options: list<array{key: string, label: string, height_u: ?int}>, suggested: ?string}|null
     */
    private static function picker(NetworkAsset $asset, ?Collection $catalogue = null): ?array
    {
        $rows = ($catalogue ?? self::catalogue($asset))->where('asset_type', $asset->asset_type);

        if ($rows->isEmpty()) {
            return null;
        }

        // The longest catalogue model that the asset's own vendor and model text contains, punctuation and case aside.
        $text = self::squash("{$asset->vendor} {$asset->model}");
        $suggested = $rows
            ->filter(fn (object $row): bool => ($model = self::squash($row->model)) !== '' && str_contains($text, $model))
            ->sortByDesc(fn (object $row): int => strlen(self::squash($row->model)))
            ->first();

        return [
            'options' => $rows->map(fn (object $row): array => [
                'key' => (string) $row->key,
                'label' => "{$row->vendor} {$row->model}",
                'height_u' => $row->height_u === null ? null : (int) $row->height_u,
            ])->values()->all(),
            'suggested' => $suggested === null ? null : (string) $suggested->key,
        ];
    }

    /**
     * The model the panel is drawn from. The layout is the asset's own snapshot; vendor and model name the catalogue
     * row it was copied from (none for a custom layout).
     *
     * @param  array<string, mixed>  $layout
     * @param  Collection<int, stdClass>|null  $catalogue  catalogue() when the caller has it already
     * @return array{key: ?string, vendor: ?string, model: ?string, height_u: ?int, layout: array<string, mixed>}
     */
    private static function template(NetworkAsset $asset, array $layout, ?Collection $catalogue = null): array
    {
        $key = $asset->template_key;
        $model = $key === null ? null : ($catalogue ?? self::catalogue($asset))->firstWhere('key', $key);

        return [
            'key' => $key,
            'vendor' => $model?->vendor,
            'model' => $model?->model,
            'height_u' => $asset->height_u,
            'layout' => $layout,
        ];
    }

    /** The asset's own port rows with the port each is linked to, in one statement. */
    private static function assetPortRows(NetworkAsset $asset, int $companyId): Collection
    {
        return DB::table('asset_ports as p')
            ->leftJoin('asset_ports as lp', fn ($join) => $join->on('lp.id', '=', 'p.linked_port_id')->on('lp.company_id', '=', 'p.company_id'))
            ->leftJoin('network_assets as la', 'la.id', '=', 'lp.network_asset_id')
            ->where('p.company_id', $companyId)
            ->where('p.network_asset_id', $asset->id)
            ->get(['p.public_id', 'p.group_key', 'p.number', 'p.name', 'p.label', 'p.admin_down', 'p.linked_port_id', 'la.code as link_asset', 'lp.name as link_port']);
    }

    /** The ODN ports that sit on this asset, with splitters, links and drops, in one statement and in no order. */
    private static function odnRows(NetworkAsset $asset, int $companyId): Collection
    {
        return self::odnQuery($companyId)->where('o.network_asset_id', $asset->id)->get(self::ODN_COLUMNS);
    }

    /**
     * odn_ports with the splitter that owns the port, the port that feeds that splitter (and its splitter, for the
     * ratio label), the splitter the port feeds, and the drop with its customer and RADIUS username.
     */
    private static function odnQuery(int $companyId): Builder
    {
        return DB::table('odn_ports as o')
            ->leftJoin('odn_splitters as s', 's.id', '=', 'o.odn_splitter_id')
            ->leftJoin('odn_ports as ip', 'ip.id', '=', 's.input_port_id')
            ->leftJoin('odn_splitters as ips', 'ips.id', '=', 'ip.odn_splitter_id')
            ->leftJoin('network_assets as ia', 'ia.id', '=', 'ip.network_asset_id')
            ->leftJoin('odn_splitters as f', 'f.input_port_id', '=', 'o.id')
            ->leftJoin('network_assets as fa', 'fa.id', '=', 'f.network_asset_id')
            ->leftJoin('service_subscriptions as sub', 'sub.id', '=', 'o.subscription_id')
            ->leftJoin('customers as c', 'c.id', '=', 'sub.customer_id')
            ->leftJoin('network_accounts as na', 'na.subscription_id', '=', 'sub.id')
            ->where('o.company_id', $companyId);
    }

    /**
     * The drop of the ONT's subscription: the subscription the asset is installed for, else the newest one whose
     * ont_asset_id is this asset. Null while the ONT has no subscription with a recorded drop.
     */
    private static function ontDrop(NetworkAsset $asset, int $companyId): ?object
    {
        $query = DB::table('service_subscriptions as sub')
            ->join('odn_ports as o', 'o.subscription_id', '=', 'sub.id')
            ->join('customers as c', 'c.id', '=', 'sub.customer_id')
            ->leftJoin('network_assets as odp', 'odp.id', '=', 'o.network_asset_id')
            ->leftJoin('odn_splitters as s', 's.id', '=', 'o.odn_splitter_id')
            ->leftJoin('network_accounts as na', 'na.subscription_id', '=', 'sub.id')
            ->leftJoin('service_packages as pk', 'pk.id', '=', 'sub.service_package_id')
            ->where('sub.company_id', $companyId)
            ->whereNull('sub.deleted_at');

        $asset->subscription_id === null
            ? $query->where('sub.ont_asset_id', $asset->id)->orderByDesc('sub.id')
            : $query->where('sub.id', $asset->subscription_id);

        return $query->first([
            'sub.public_id as sub_id', 'sub.code as sub_code', 'sub.status as sub_status', 'sub.blocked_at as sub_blocked_at',
            'c.public_id as customer_id', 'c.name as customer_name', 'pk.name as package_name', 'na.username',
            'o.number as port_number', 'odp.code as odp_code', 's.outputs as splitter_outputs', 's.label as splitter_label',
        ]);
    }

    /**
     * The drops below every port of $rows that feeds a splitter, through any number of splitter levels, in one
     * recursive statement. Only integer ids and the company id are bound.
     *
     * @return array<int, list<?string>> port id => the lower-cased account username (null without an account) of each subscriber below it
     */
    private static function behind(int $companyId, Collection $rows): array
    {
        $roots = $rows->whereNotNull('fed_splitter_id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($roots === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($roots), '?'));

        $found = DB::select(<<<SQL
            WITH RECURSIVE tree (root_id, port_id, depth) AS (
                SELECT p.id, p.id, 0 FROM odn_ports p WHERE p.company_id = ? AND p.id IN ({$marks})
                UNION ALL
                SELECT t.root_id, c.id, t.depth + 1
                FROM tree t
                JOIN odn_splitters s ON s.input_port_id = t.port_id AND s.company_id = ?
                JOIN odn_ports c ON c.odn_splitter_id = s.id AND c.company_id = ?
                WHERE t.depth < ?
            )
            SELECT t.root_id, na.username
            FROM tree t
            JOIN odn_ports p ON p.id = t.port_id AND t.depth > 0 AND p.subscription_id IS NOT NULL
            LEFT JOIN network_accounts na ON na.subscription_id = p.subscription_id
            SQL, [$companyId, ...$roots, $companyId, $companyId, self::MAX_HOPS]);

        $below = [];

        foreach ($found as $row) {
            $below[(int) $row->root_id][] = self::lower($row->username);
        }

        return $below;
    }

    /**
     * The ports from the subscription's drop up to the PON port, one row per port with its asset and splitter, the
     * PON port first. At most MAX_HOPS + 1 splitter levels are walked, so a cycle in bad data ends the walk.
     */
    private static function pathRows(int $companyId, int $subscriptionId): Collection
    {
        return collect(DB::select(<<<'SQL'
            WITH RECURSIVE path (port_id, depth) AS (
                SELECT p.id, 0 FROM odn_ports p WHERE p.company_id = ? AND p.subscription_id = ?
                UNION ALL
                SELECT u.id, path.depth + 1
                FROM path
                JOIN odn_ports c ON c.id = path.port_id AND c.company_id = ?
                JOIN odn_splitters s ON s.id = c.odn_splitter_id AND s.company_id = ?
                JOIN odn_ports u ON u.id = s.input_port_id AND u.company_id = ?
                WHERE path.depth < ?
            )
            SELECT p.kind, p.number, a.public_id AS asset_id, a.code, a.name, a.asset_type,
                   CASE WHEN a.asset_type = 'olt' THEN a.port_layout END AS port_layout,
                   s.outputs AS splitter_outputs, s.label AS splitter_label
            FROM path
            JOIN odn_ports p ON p.id = path.port_id
            JOIN network_assets a ON a.id = p.network_asset_id
            LEFT JOIN odn_splitters s ON s.id = p.odn_splitter_id
            ORDER BY path.depth DESC
            SQL, [$companyId, $subscriptionId, $companyId, $companyId, $companyId, self::MAX_HOPS + 1]));
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function pathHops(Collection $rows): array
    {
        return $rows->map(function (object $row): array {
            $asset = ['id' => (string) $row->asset_id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'type' => (string) $row->asset_type];

            if ($row->kind === OdnPort::KIND_PON) {
                $layout = self::decode($row->port_layout);
                $slot = $layout === null ? null : (PanelLayout::ponSlots($layout)[(int) $row->number] ?? null);

                return [
                    'asset' => $asset,
                    'label' => $slot['name'] ?? "PON {$row->number}",
                    'layout' => $layout,
                    'lit' => $slot === null ? null : ['group' => $slot['group'], 'number' => $slot['number']],
                ];
            }

            $ratio = OdnOccupancyQuery::ratio((int) $row->splitter_outputs, $row->splitter_label);

            return [
                'asset' => $asset,
                'label' => "{$row->code} · {$ratio} · {$row->number}",
                'layout' => ['rows' => [self::splitterRow(1, (int) $row->splitter_outputs, $ratio)]],
                'lit' => ['group' => 's1', 'number' => (int) $row->number],
            ];
        })->all();
    }

    /** The customer end: the ONT (or other CPE) the subscription names, else a labelled box. */
    private static function customerHop(int $companyId, object $subscription): array
    {
        $query = DB::table('network_assets')->where('company_id', $companyId)->whereNull('deleted_at');

        $subscription->ont_asset_id === null
            ? $query->where('asset_type', 'onu_ont')->where('subscription_id', $subscription->id)
            : $query->where('id', $subscription->ont_asset_id);

        $cpe = $query->first(['public_id', 'code', 'name', 'asset_type', 'port_layout']);
        $layout = $cpe === null ? null : self::decode($cpe->port_layout);
        $pon = collect($layout['rows'] ?? [])->flatten(1)->firstWhere('group', 'pon');

        return [
            'asset' => $cpe === null ? null : ['id' => (string) $cpe->public_id, 'code' => (string) $cpe->code, 'name' => (string) $cpe->name, 'type' => (string) $cpe->asset_type],
            'label' => (string) $subscription->customer_name,
            'layout' => $layout,
            'lit' => $pon === null ? null : ['group' => 'pon', 'number' => (int) ($pon['from'] ?? 1)],
        ];
    }

    /**
     * Who of these usernames has an open session, from one radacct query reused for 15 seconds. A RADIUS that is off
     * or down is not an error and is never cached: the panel renders without online flags.
     *
     * @param  list<?string>  $usernames
     * @return array{known: bool, reason: ?string, set: array<string, true>}
     */
    private static function online(int $companyId, string $scope, array $usernames): array
    {
        $names = array_values(array_unique(array_filter(array_map(self::lower(...), $usernames))));

        if ($names === []) {
            return ['known' => true, 'reason' => null, 'set' => []];
        }

        sort($names);
        $key = sprintf('asset-panel:online:%d:%s:%s:%s', $companyId, Cache::get(self::versionKey($companyId), '1'), $scope, sha1(implode("\n", $names)));

        try {
            $open = Cache::remember($key, self::ONLINE_TTL, fn (): array => array_map(self::lower(...), app(RadiusDirectory::class)->onlineAmong($names)));
        } catch (RadiusException $e) {
            return ['known' => false, 'reason' => $e->kind === RadiusException::DISABLED ? 'disabled' : 'unavailable', 'set' => []];
        }

        return ['known' => true, 'reason' => null, 'set' => array_fill_keys($open, true)];
    }

    private static function versionKey(int $companyId): string
    {
        return "asset-panel:online:{$companyId}:version";
    }

    /**
     * Every account username the panel will ask RADIUS about: the drops listed, those below them, and the ONT's own.
     *
     * @param  Collection<int, object>  $odn
     * @param  array<int, list<?string>>  $behind
     * @return list<?string>
     */
    private static function usernames(Collection $odn, array $behind, ?object $drop = null): array
    {
        return [...$odn->pluck('username')->all(), ...array_merge([], ...array_values($behind)), $drop?->username];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, array<string, mixed>> the groups of the layout in reading order, without ports yet
     */
    private static function groupSkeletons(array $layout): array
    {
        $groups = [];

        foreach ($layout['rows'] as $row) {
            foreach ($row as $block) {
                if (isset($block['group'])) {
                    $groups[$block['group']] ??= [
                        'key' => $block['group'],
                        'kind' => $block['kind'],
                        'role' => ($block['role'] ?? null) === 'pon' ? 'pon' : 'port',
                        'label' => $block['title'] ?? $block['group'],
                        'ports' => [],
                    ];
                }
            }
        }

        return $groups;
    }

    /**
     * The layout without its PON blocks; a chassis row that held only PON blocks and fixtures goes with them.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private static function withoutPon(array $layout): array
    {
        $rows = [];

        foreach ($layout['rows'] as $row) {
            $kept = array_values(array_filter($row, fn (array $block): bool => ($block['role'] ?? null) !== 'pon'));
            $hasPorts = array_filter($kept, fn (array $block): bool => isset($block['group'])) !== [];

            if (count($kept) === count($row) || $hasPorts) {
                $rows[] = $kept;
            }
        }

        return ['rows' => $rows];
    }

    /**
     * One port of the register, or the slot the layout calls for when the register has none.
     *
     * @param  array{group_key: string, number: int, kind: string, name: string}  $slot
     * @param  array{known: bool, reason: ?string, set: array<string, true>}  $online
     */
    private static function assetPort(array $slot, ?object $row, ?object $drop, array $online): array
    {
        if ($row === null) {
            return self::port(null, $slot['number'], $slot['name'], null, ['unregistered' => true]);
        }

        $linked = $row->linked_port_id !== null && $row->link_asset !== null;
        $link = $linked ? ['asset' => (string) $row->link_asset, 'port' => (string) $row->link_port] : null;

        // The ONT's PON port takes the drop of its subscription: the fibre is what it is plugged into.
        if ($drop === null || $slot['group_key'] !== 'pon') {
            return self::port((string) $row->public_id, $slot['number'], (string) $row->name, $row->label, ['occupied' => $row->linked_port_id !== null, 'admin_down' => (bool) $row->admin_down], $link);
        }

        $username = self::lower($drop->username);

        return self::port(
            id: (string) $row->public_id,
            number: $slot['number'],
            name: (string) $row->name,
            label: $row->label,
            state: [
                'occupied' => true,
                'online' => $username !== null && isset($online['set'][$username]),
                'isolir' => self::isolated($drop->sub_status, $drop->sub_blocked_at),
                'admin_down' => (bool) $row->admin_down,
            ],
            link: $link ?? ['asset' => (string) $drop->odp_code, 'port' => OdnOccupancyQuery::portLabel(OdnPort::KIND_SPLITTER_OUT, (int) $drop->port_number, (int) $drop->splitter_outputs, $drop->splitter_label)],
            drop: ['subscription' => (string) $drop->sub_code, 'customer' => (string) $drop->customer_name],
        );
    }

    /**
     * A PON port or a splitter output of the ODN register.
     *
     * @param  array<int, list<?string>>  $behind
     * @param  array{known: bool, reason: ?string, set: array<string, true>}  $online
     */
    private static function odnPort(object $row, int $number, string $name, array $behind, array $online): array
    {
        $feeds = $row->fed_splitter_id !== null;
        $below = $feeds ? self::behindStats($behind[(int) $row->id] ?? [], $online) : null;
        $held = $row->subscription_id !== null;
        $username = self::lower($row->username);

        return self::port(
            id: (string) $row->public_id,
            number: $number,
            name: $name,
            label: $row->label,
            state: [
                'occupied' => $held || $feeds,
                'online' => ($held && $username !== null && isset($online['set'][$username])) || ($below['online'] ?? 0) > 0,
                'isolir' => $held && self::isolated($row->sub_status, $row->sub_blocked_at),
            ],
            link: $feeds ? ['asset' => (string) $row->fed_asset, 'port' => OdnOccupancyQuery::ratio((int) $row->fed_outputs, $row->fed_label)] : null,
            drop: $held ? ['subscription' => (string) $row->sub_code, 'customer' => (string) $row->customer_name] : null,
            behind: $below,
        );
    }

    /**
     * The PON groups of an OLT: each slot of the model joined to the register row with its number as ordinal, a slot
     * without a row drawn as unregistered, and rows past the model's slots in a group of their own.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<int, array{group: string, number: int, name: string}>  $slots
     * @param  array<int, list<?string>>  $behind
     * @param  array{known: bool, reason: ?string, set: array<string, true>}  $online
     * @return array<string, array<string, mixed>>
     */
    private static function ponGroups(array $groups, array $slots, Collection $odn, array $behind, array $online): array
    {
        $rows = $odn->where('kind', OdnPort::KIND_PON)->keyBy(fn (object $row): int => (int) $row->number);

        foreach ($slots as $ordinal => $slot) {
            $row = $rows->get($ordinal);

            $groups[$slot['group']]['ports'][] = $row === null
                ? self::port(null, $slot['number'], $slot['name'], null, ['unregistered' => true])
                : self::odnPort($row, $slot['number'], $slot['name'], $behind, $online);
        }

        $extra = $rows->only($rows->keys()->filter(fn (int $number): bool => $number > count($slots))->all());

        if ($extra->isNotEmpty()) {
            $groups['ponx'] = [
                'key' => 'ponx',
                'kind' => 'sfp',
                'role' => 'pon',
                'label' => 'Port PON di luar model',
                'ports' => $extra->values()->map(fn (object $row, int $position): array => self::odnPort($row, $position + 1, 'PON '.$row->number, $behind, $online))->all(),
            ];
        }

        return $groups;
    }

    /**
     * The layout plus one chassis row for the PON ports past the model's slots, when the groups hold any.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private static function withExtraPonRows(array $layout, array $groups): array
    {
        if (! isset($groups['ponx'])) {
            return $layout;
        }

        $count = count($groups['ponx']['ports']);
        $layout['rows'][] = [['group' => 'ponx', 'kind' => 'sfp', 'count' => $count, 'stack' => $count > 16 ? 2 : 1, 'role' => 'pon', 'name' => 'PON {port}', 'title' => 'Port PON di luar model']];

        return $layout;
    }

    /**
     * One chassis row of a splitter: its IN and its outputs (two port rows from 16 outputs up).
     *
     * @return list<array<string, mixed>>
     */
    private static function splitterRow(int $n, int $outputs, string $ratio): array
    {
        return [
            ['group' => "in{$n}", 'kind' => 'sc', 'count' => 1, 'name' => 'IN', 'title' => 'Masuk'],
            ['group' => "s{$n}", 'kind' => 'sc', 'count' => $outputs, 'stack' => $outputs >= 16 ? 2 : 1, 'order' => 'row', 'name' => '{port}', 'role' => 'splitter', 'title' => "Splitter {$ratio}"],
        ];
    }

    /**
     * @param  list<?string>  $usernames
     * @param  array{known: bool, reason: ?string, set: array<string, true>}  $online
     * @return array{total: int, online: int}
     */
    private static function behindStats(array $usernames, array $online): array
    {
        return [
            'total' => count($usernames),
            'online' => count(array_filter($usernames, fn (?string $username): bool => $username !== null && isset($online['set'][$username]))),
        ];
    }

    /**
     * @param  array<string, bool>  $state  flags that are absent are false
     * @param  array{asset: string, port: string}|null  $link
     * @param  array{subscription: string, customer: string}|null  $drop
     * @param  array{total: int, online: int}|null  $behind
     * @return array<string, mixed>
     */
    private static function port(?string $id, int $number, string $name, ?string $label, array $state, ?array $link = null, ?array $drop = null, ?array $behind = null): array
    {
        return [
            'id' => $id,
            'number' => $number,
            'name' => $name,
            'label' => filled($label) ? $label : null,
            'state' => $state + self::NO_STATE,
            'link' => $link,
            'drop' => $drop,
            'behind' => $behind,
        ];
    }

    /** The isolir rule of NetworkAccountProvisioner::isolatedBecause(): suspended or blocked, unless terminated. */
    private static function isolated(?string $status, mixed $blockedAt): bool
    {
        return $status !== null && $status !== 'terminated' && ($status === 'suspended' || $blockedAt !== null);
    }

    /** @return array<string, mixed> */
    private static function assetPortDetail(NetworkAsset $asset, object $port, bool $withOdn, int $companyId): array
    {
        $drop = $withOdn && $port->group_key === 'pon' && $asset->asset_type === 'onu_ont' ? self::ontDrop($asset, $companyId) : null;

        return [
            'id' => (string) $port->public_id,
            'source' => 'asset',
            'name' => (string) $port->name,
            'label' => $port->label,
            'note' => $port->note,
            'admin_down' => (bool) $port->admin_down,
            'link' => $port->link_port_id === null ? null : [
                'asset' => ['id' => (string) $port->link_asset_id, 'code' => (string) $port->link_asset, 'name' => (string) $port->link_asset_name],
                'port' => ['id' => (string) $port->link_port_id, 'name' => (string) $port->link_port],
            ],
            'feeds' => null,
            'drop' => $drop === null ? null : self::dropDetail($drop),
            'behind' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function odnPortDetail(NetworkAsset $asset, object $row, int $companyId): array
    {
        $feeds = $row->fed_splitter_id !== null;
        $below = null;

        if ($feeds) {
            $usernames = self::behind($companyId, collect([$row]))[(int) $row->id] ?? [];
            $below = self::behindStats($usernames, self::online($companyId, "port:{$row->id}", $usernames));
        }

        $name = $row->kind === OdnPort::KIND_PON ? self::ponName($asset->port_layout, (int) $row->number) : (string) $row->number;

        return [
            'id' => (string) $row->public_id,
            'source' => 'odn',
            'name' => $name,
            'label' => $row->label,
            'note' => null,
            'admin_down' => false,
            'link' => null,
            'feeds' => $feeds ? ['asset' => ['id' => (string) $row->fed_asset_id, 'code' => (string) $row->fed_asset], 'splitter' => OdnOccupancyQuery::ratio((int) $row->fed_outputs, $row->fed_label)] : null,
            'drop' => $row->subscription_id === null ? null : self::dropDetail($row),
            'behind' => $below,
        ];
    }

    /**
     * The subscription behind a drop, with "online since" read live (no cache) for this one username.
     *
     * @return array<string, mixed>
     */
    private static function dropDetail(object $row): array
    {
        return [
            'subscription' => ['id' => (string) $row->sub_id, 'code' => (string) $row->sub_code, 'status' => (string) $row->sub_status, 'blocked' => $row->sub_blocked_at !== null],
            'customer' => ['id' => (string) $row->customer_id, 'name' => (string) $row->customer_name],
            'package' => $row->package_name ?? null,
            'session' => self::session($row->username),
        ];
    }

    /** @return array{online: bool, since: ?string}|null null while RADIUS is off, unreachable, or the subscription has no account */
    private static function session(?string $username): ?array
    {
        if ($username === null) {
            return null;
        }

        try {
            $sessions = app(RadiusDirectory::class)->openSessions($username);
        } catch (RadiusException) {
            return null;
        }

        return ['online' => $sessions !== [], 'since' => $sessions[0]['started_at'] ?? null];
    }

    /** The vendor name of the PON port with this ordinal ("gpon-olt_1/1/3"); "PON 3" while the OLT has no model or the slot is not in it. */
    private static function ponName(?array $layout, int $ordinal): string
    {
        $slot = $layout === null ? null : (PanelLayout::ponSlots($layout)[$ordinal] ?? null);

        return $slot['name'] ?? "PON {$ordinal}";
    }

    /** @return array<string, mixed>|null */
    private static function decode(mixed $json): ?array
    {
        return is_string($json) ? json_decode($json, true) : null;
    }

    /** Punctuation and case aside: "RB4011iGS+RM" and "rb4011 igs rm" are one model. */
    private static function squash(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($text))) ?? '';
    }

    /** MySQL compares usernames without case, and RADIUS stores what the NAS sent: usernames are compared lower-cased. */
    private static function lower(mixed $username): ?string
    {
        return is_string($username) && $username !== '' ? Str::lower($username) : null;
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }
}
