<?php

namespace Modules\Ipam\Services;

use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Support\IpamLock;
use Modules\Ipam\Support\IpNotation;
use Modules\Ipam\Support\ParsedPrefix;

class IpPrefixService
{
    /**
     * Registers a prefix under the smallest registered prefix that contains it and adopts the existing
     * prefixes and addresses it contains. Prefix changes of one company run one at a time.
     *
     * @param  array{cidr: string, usage: string, location_id?: int|null, vlan_id?: int|null, description?: string|null}  $data
     */
    public static function create(array $data): IpPrefix
    {
        $companyId = self::companyId();
        $parsed = IpNotation::prefix($data['cidr']) ?? throw ValidationException::withMessages([
            'cidr' => 'Masukkan prefiks IPv4 atau IPv6 dalam bentuk CIDR yang valid, misalnya 10.20.0.0/22.',
        ]);

        return IpamLock::run('prefixes', $companyId, function () use ($data, $parsed): IpPrefix {
            self::assertLinks($data);

            if (IpPrefix::query()->where('cidr', $parsed->cidr)->lockForUpdate()->exists()) {
                throw self::duplicate($parsed);
            }

            [$parent, $inside] = self::place($parsed);

            try {
                $prefix = IpPrefix::query()->create([
                    ...Arr::only($data, ['usage', 'location_id', 'vlan_id', 'description']),
                    'parent_id' => $parent?->id,
                    'family' => $parsed->family,
                    'cidr' => $parsed->cidr,
                    'range_from' => $parsed->from,
                    'range_to' => $parsed->to,
                    'prefix_length' => $parsed->length,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw self::duplicate($parsed);
            }

            // The new prefix now sits between its parent and the prefixes and addresses it contains.
            IpPrefix::query()
                ->whereIn('id', array_map(fn (IpPrefix $row): int => $row->id, array_filter($inside, fn (IpPrefix $row): bool => $row->parent_id === $parent?->id)))
                ->update(['parent_id' => $prefix->id]);

            IpAddress::query()
                ->where('family', $parsed->family)
                ->whereBetween('address_cmp', [$parsed->from, $parsed->to])
                ->when(
                    $parent !== null,
                    fn ($query) => $query->where('ip_prefix_id', $parent?->id),
                    fn ($query) => $query->whereNull('ip_prefix_id'),
                )
                ->update(['ip_prefix_id' => $prefix->id]);

            return $prefix;
        });
    }

    /**
     * The cidr is immutable; only how the block is used and tied can change.
     *
     * @param  array{usage: string, location_id?: int|null, vlan_id?: int|null, description?: string|null}  $data
     */
    public static function update(IpPrefix $prefix, array $data): IpPrefix
    {
        return IpamLock::run('prefixes', self::companyId(), function () use ($prefix, $data): IpPrefix {
            self::assertLinks($data);

            $fresh = IpPrefix::query()->lockForUpdate()->findOrFail($prefix->id);
            $fresh->update(Arr::only($data, ['usage', 'location_id', 'vlan_id', 'description']));

            return $fresh;
        });
    }

    public static function delete(IpPrefix $prefix): void
    {
        IpamLock::run('prefixes', self::companyId(), function () use ($prefix): void {
            $fresh = IpPrefix::query()->lockForUpdate()->findOrFail($prefix->id);

            if ($fresh->children()->exists()) {
                throw ValidationException::withMessages(['prefix' => "{$fresh->cidr} memuat prefiks lain. Hapus dulu."]);
            }

            if (IpAddress::query()->where('ip_prefix_id', $fresh->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['prefix' => "{$fresh->cidr} masih punya alamat. Lepas dulu."]);
            }

            $fresh->delete();
        });
    }

    /**
     * Splits every registered prefix that overlaps the new one into containers of it and prefixes it
     * contains. CIDR blocks nest or are disjoint, so anything else is a corrupt row and is refused.
     *
     * @return array{0: ?IpPrefix, 1: list<IpPrefix>} the smallest containing prefix, the prefixes inside
     */
    private static function place(ParsedPrefix $new): array
    {
        $parent = null;
        $inside = [];

        $overlapping = IpPrefix::query()
            ->where('family', $new->family)
            ->where('range_from', '<=', $new->to)
            ->where('range_to', '>=', $new->from)
            ->lockForUpdate()
            ->get();

        foreach ($overlapping as $row) {
            $other = new ParsedPrefix($row->cidr, $row->family, $row->range_from, $row->range_to, $row->prefix_length);

            if ($other->containsPrefix($new)) {
                $parent = $parent === null || $row->prefix_length > $parent->prefix_length ? $row : $parent;
            } elseif ($new->containsPrefix($other)) {
                $inside[] = $row;
            } else {
                throw ValidationException::withMessages(['cidr' => "{$new->cidr} tumpang tindih sebagian dengan {$row->cidr}."]);
            }
        }

        return [$parent, $inside];
    }

    /** @param array{location_id?: int|null, vlan_id?: int|null} $data */
    private static function assertLinks(array $data): void
    {
        if (($data['location_id'] ?? null) !== null && ! Location::query()->whereKey($data['location_id'])->exists()) {
            throw ValidationException::withMessages(['location_id' => 'POP yang dipilih tidak ditemukan.']);
        }

        if (($data['vlan_id'] ?? null) !== null && ! Vlan::query()->whereKey($data['vlan_id'])->exists()) {
            throw ValidationException::withMessages(['vlan_id' => 'VLAN yang dipilih tidak ditemukan.']);
        }
    }

    private static function duplicate(ParsedPrefix $parsed): ValidationException
    {
        return ValidationException::withMessages(['cidr' => "{$parsed->cidr} sudah terdaftar."]);
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }
}
