<?php

namespace Modules\Ipam\Database\Factories;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Support\IpNotation;

/**
 * A reserved row (no holder) by default; forSubscription() and forAsset() make it an assigned one.
 * Raw rows: ip_prefix_id is not computed here.
 *
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        self::$sequence++;

        return [
            'company_id' => CompanyService::currentId() ?? Company::factory(),
            'ip_prefix_id' => null,
            'status' => IpAddress::STATUS_RESERVED,
            'subscription_id' => null,
            'network_asset_id' => null,
            'note' => null,
            'assigned_by' => null,
            ...$this->derived('172.16.'.intdiv(self::$sequence, 256).'.'.(self::$sequence % 256)),
        ];
    }

    public function address(string $address): static
    {
        return $this->state(fn (): array => $this->derived($address));
    }

    public function forSubscription(int $subscriptionId): static
    {
        return $this->state(['status' => IpAddress::STATUS_ASSIGNED, 'subscription_id' => $subscriptionId]);
    }

    public function forAsset(int $assetId): static
    {
        return $this->state(['status' => IpAddress::STATUS_ASSIGNED, 'network_asset_id' => $assetId]);
    }

    /** @return array<string, int|string> */
    private function derived(string $address): array
    {
        $parsed = IpNotation::address($address) ?? throw new InvalidArgumentException("Not an IP address: {$address}");

        return [
            'family' => $parsed->family,
            'address' => $parsed->address,
            'address_cmp' => $parsed->comparable,
        ];
    }
}
