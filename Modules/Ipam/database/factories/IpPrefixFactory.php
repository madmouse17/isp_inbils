<?php

namespace Modules\Ipam\Database\Factories;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Support\IpNotation;

/**
 * Raw rows: parent links are not computed here (IpPrefixService does that), only the derived columns.
 *
 * @extends Factory<IpPrefix>
 */
class IpPrefixFactory extends Factory
{
    protected $model = IpPrefix::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        self::$sequence++;

        return [
            'company_id' => CompanyService::currentId() ?? Company::factory(),
            'parent_id' => null,
            'usage' => 'customer_static',
            'location_id' => null,
            'vlan_id' => null,
            'description' => null,
            ...$this->derived('10.'.intdiv(self::$sequence, 256).'.'.(self::$sequence % 256).'.0/24'),
        ];
    }

    public function cidr(string $cidr): static
    {
        return $this->state(fn (): array => $this->derived($cidr));
    }

    public function usage(string $usage): static
    {
        return $this->state(['usage' => $usage]);
    }

    /** @return array<string, int|string> */
    private function derived(string $cidr): array
    {
        $parsed = IpNotation::prefix($cidr) ?? throw new InvalidArgumentException("Not a CIDR prefix: {$cidr}");

        return [
            'family' => $parsed->family,
            'cidr' => $parsed->cidr,
            'range_from' => $parsed->from,
            'range_to' => $parsed->to,
            'prefix_length' => $parsed->length,
        ];
    }
}
