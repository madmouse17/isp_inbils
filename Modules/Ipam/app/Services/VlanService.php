<?php

namespace Modules\Ipam\Services;

use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Support\IpamLock;

class VlanService
{
    /** @param array{vid: int, name: string, location_id?: int|null, description?: string|null} $data */
    public static function create(array $data): Vlan
    {
        return IpamLock::run('vlans', self::companyId(), function () use ($data): Vlan {
            self::assertValid($data);

            return Vlan::query()->create(Arr::only($data, ['location_id', 'vid', 'name', 'description']));
        });
    }

    /** @param array{vid: int, name: string, location_id?: int|null, description?: string|null} $data */
    public static function update(Vlan $vlan, array $data): Vlan
    {
        return IpamLock::run('vlans', self::companyId(), function () use ($vlan, $data): Vlan {
            self::assertValid($data, $vlan->id);

            $fresh = Vlan::query()->lockForUpdate()->findOrFail($vlan->id);
            $fresh->update(Arr::only($data, ['location_id', 'vid', 'name', 'description']));

            return $fresh;
        });
    }

    public static function delete(Vlan $vlan): void
    {
        IpamLock::run('vlans', self::companyId(), function () use ($vlan): void {
            $fresh = Vlan::query()->lockForUpdate()->findOrFail($vlan->id);

            if (IpPrefix::query()->where('vlan_id', $fresh->id)->exists()) {
                throw ValidationException::withMessages(['vlan' => "VLAN {$fresh->vid} is used by a prefix. Detach it first."]);
            }

            $fresh->delete();
        });
    }

    /**
     * The unique index covers a VID once per POP; MySQL treats NULL as distinct, so a company-wide VID is checked here.
     *
     * @param  array{vid: int, location_id?: int|null}  $data
     */
    private static function assertValid(array $data, ?int $ignoreId = null): void
    {
        $locationId = $data['location_id'] ?? null;

        if ($locationId !== null && ! Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'The selected POP does not exist.']);
        }

        $taken = Vlan::query()
            ->where('vid', $data['vid'])
            ->when($locationId === null, fn ($query) => $query->whereNull('location_id'), fn ($query) => $query->where('location_id', $locationId))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'vid' => $locationId === null
                    ? "VLAN {$data['vid']} is already registered company-wide."
                    : "VLAN {$data['vid']} is already registered at this POP.",
            ]);
        }
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }
}
