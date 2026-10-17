<?php

namespace Modules\Ipam\Queries;

use App\Models\Core\Location;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Support\IpNotation;

/**
 * Prefix rows with their fill level. Assigned and reserved addresses are counted by range, so a block
 * includes the addresses of the prefixes nested inside it. Read-only.
 */
final class IpPrefixUtilisationQuery
{
    /** Usages that hold addresses; the others show size (dynamic) or child coverage (container). */
    private const ADDRESS_POOLS = ['customer_static', 'infrastructure'];

    /**
     * @param  array<string, mixed>  $filters  family, usage, location_id and vlan_id (public ids), search
     * @return list<array<string, mixed>> flat, family then first address; the UI indents by depth
     */
    public static function rows(array $filters = []): array
    {
        $prefixes = self::filtered(self::base(), $filters)
            ->orderBy('family')
            ->orderBy('range_from')
            ->orderBy('prefix_length')
            ->get();

        $depths = self::depths();
        $childLengths = self::childLengths();

        return $prefixes
            ->map(fn (IpPrefix $prefix): array => self::format($prefix, $depths[$prefix->id] ?? 0, $childLengths[$prefix->id] ?? []))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function row(IpPrefix $prefix): array
    {
        $row = self::base()->whereKey($prefix->id)->firstOrFail();

        return self::format($row, self::depths()[$row->id] ?? 0, self::childLengths()[$row->id] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public static function children(IpPrefix $prefix): array
    {
        $depth = (self::depths()[$prefix->id] ?? 0) + 1;
        $childLengths = self::childLengths();

        return self::base()
            ->where('parent_id', $prefix->id)
            ->orderBy('range_from')
            ->get()
            ->map(fn (IpPrefix $child): array => self::format($child, $depth, $childLengths[$child->id] ?? []))
            ->all();
    }

    /**
     * Static pools a subscription can draw from: company-wide ones and those tied to its serving POP.
     * free is the number of usable addresses left (IPv4); null for IPv6, where addresses are typed.
     *
     * @return list<array{id: string, cidr: string, family: int, free: string|null}>
     */
    public static function staticPools(?int $servingPopId): array
    {
        return self::base()
            ->where('usage', 'customer_static')
            ->where(fn (Builder $query) => $query->whereNull('location_id')->when($servingPopId !== null, fn (Builder $q) => $q->orWhere('location_id', $servingPopId)))
            ->orderBy('family')
            ->orderBy('range_from')
            ->get()
            ->map(fn (IpPrefix $pool): array => [
                'id' => $pool->public_id,
                'cidr' => $pool->cidr,
                'family' => $pool->family,
                'free' => self::format($pool, 0, [])['free'],
            ])
            ->all();
    }

    /** @return Builder<IpPrefix> */
    private static function base(): Builder
    {
        return IpPrefix::query()
            ->select('ip_prefixes.*')
            ->selectSub(self::countWithin('assigned'), 'assigned_count')
            ->selectSub(self::countWithin('reserved'), 'reserved_count')
            ->with(['location:id,public_id,code', 'vlan:id,public_id,vid,name', 'parent:id,public_id']);
    }

    /** Correlated count of addresses of one status inside the outer prefix's range; uses the (company_id, address_cmp) index. */
    private static function countWithin(string $status): QueryBuilder
    {
        return DB::table('ip_addresses')
            ->selectRaw('count(*)')
            ->whereColumn('ip_addresses.company_id', 'ip_prefixes.company_id')
            ->whereColumn('ip_addresses.family', 'ip_prefixes.family')
            ->whereColumn('ip_addresses.address_cmp', '>=', 'ip_prefixes.range_from')
            ->whereColumn('ip_addresses.address_cmp', '<=', 'ip_prefixes.range_to')
            ->where('ip_addresses.status', $status);
    }

    /**
     * @param  Builder<IpPrefix>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<IpPrefix>
     */
    private static function filtered(Builder $query, array $filters): Builder
    {
        $family = (int) ($filters['family'] ?? 0);
        $usage = $filters['usage'] ?? null;
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        return $query
            ->when(in_array($family, [4, 6], true), fn (Builder $q) => $q->where('family', $family))
            ->when(is_string($usage) && in_array($usage, IpPrefix::USAGES, true), fn (Builder $q) => $q->where('usage', $usage))
            ->when(filled($filters['location_id'] ?? null), fn (Builder $q) => $q->where('location_id', PublicId::resolveOne(Location::class, $filters['location_id']) ?? 0))
            ->when(filled($filters['vlan_id'] ?? null), fn (Builder $q) => $q->where('vlan_id', PublicId::resolveOne(Vlan::class, $filters['vlan_id']) ?? 0))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('cidr', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')));
    }

    /**
     * @param  list<int>  $childLengths  prefix lengths of the direct children
     * @return array<string, mixed>
     */
    private static function format(IpPrefix $prefix, int $depth, array $childLengths): array
    {
        $assigned = (int) $prefix->getAttribute('assigned_count');
        $reserved = (int) $prefix->getAttribute('reserved_count');
        $size = IpNotation::size($prefix->cidr);
        $usable = $free = $percent = null;

        if ($prefix->family === 4 && in_array($prefix->usage, self::ADDRESS_POOLS, true)) {
            $addresses = (int) $size;
            $usableCount = $prefix->prefix_length <= 30 ? $addresses - 2 : $addresses;
            $usable = (string) $usableCount;
            $free = (string) max(0, $usableCount - $assigned - $reserved);
            $percent = $usableCount > 0 ? round(($assigned + $reserved) / $usableCount * 100, 2) : null;
        } elseif ($prefix->usage === 'container') {
            $covered = array_sum(array_map(fn (int $length): float => 2 ** ($prefix->prefix_length - $length), $childLengths));
            $percent = round($covered * 100, 2);
        }

        return [
            'id' => $prefix->public_id,
            'parent_id' => $prefix->parent?->public_id,
            'depth' => $depth,
            'cidr' => $prefix->cidr,
            'family' => $prefix->family,
            'usage' => $prefix->usage,
            'location' => $prefix->location === null ? null : ['id' => $prefix->location->public_id, 'code' => $prefix->location->code],
            'vlan' => $prefix->vlan === null ? null : ['id' => $prefix->vlan->public_id, 'vid' => $prefix->vlan->vid, 'name' => $prefix->vlan->name],
            'description' => $prefix->description,
            'size' => $size,
            'usable' => $usable,
            'assigned' => $assigned,
            'reserved' => $reserved,
            'free' => $free,
            'percent' => $percent,
        ];
    }

    /** @return array<int, int> prefix id => number of ancestors, over the whole company tree */
    private static function depths(): array
    {
        $parents = IpPrefix::query()->pluck('parent_id', 'id')->all();
        $depths = [];

        foreach (array_keys($parents) as $id) {
            $depth = 0;

            // The parent links form a forest; the cap only guards a corrupt row from looping forever.
            for ($cursor = $parents[$id]; $cursor !== null && $depth < 64; $cursor = $parents[$cursor] ?? null) {
                $depth++;
            }

            $depths[$id] = $depth;
        }

        return $depths;
    }

    /** @return array<int, list<int>> parent id => prefix lengths of its direct children */
    private static function childLengths(): array
    {
        $lengths = [];

        foreach (IpPrefix::query()->whereNotNull('parent_id')->get(['parent_id', 'prefix_length']) as $child) {
            $lengths[(int) $child->parent_id][] = $child->prefix_length;
        }

        return $lengths;
    }
}
