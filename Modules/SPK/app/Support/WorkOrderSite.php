<?php

namespace Modules\SPK\Support;

use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use Modules\SPK\Models\WorkOrder;

/**
 * Where the technician goes. For an SPK of a subscription that is the customer's installation address; the POP the
 * SPK is stocked from (`location_id`, which onboarding sets to the serving POP) is a place of its own and never the
 * destination. An SPK without a subscription goes to its location. An address without coordinates keeps its text for
 * a search in Maps and has no distance to measure: it never borrows the POP's coordinates.
 *
 * Needs `subscription.installationAddress` and `location` loaded.
 */
final class WorkOrderSite
{
    /** @return array{label: string, address: ?string, lat: ?float, lng: ?float}|null */
    public static function site(WorkOrder $wo): ?array
    {
        $address = self::installationAddress($wo);

        return $address === null ? self::location($wo) : [
            'label' => $address->label,
            'address' => self::text($address->address, $address->city),
            'lat' => self::coordinate($address->lat),
            'lng' => self::coordinate($address->lng),
        ];
    }

    /**
     * The POP, only where the installation address is the destination: otherwise it already is the site.
     *
     * @return array{label: string, address: ?string, lat: ?float, lng: ?float}|null
     */
    public static function pop(WorkOrder $wo): ?array
    {
        return self::installationAddress($wo) === null ? null : self::location($wo);
    }

    private static function installationAddress(WorkOrder $wo): ?CustomerAddress
    {
        $address = $wo->subscription?->installationAddress;

        return $address instanceof CustomerAddress ? $address : null;
    }

    /** @return array{label: string, address: ?string, lat: ?float, lng: ?float}|null */
    private static function location(WorkOrder $wo): ?array
    {
        $location = $wo->location;

        return $location instanceof Location ? [
            'label' => $location->name,
            'address' => self::text($location->address),
            'lat' => self::coordinate($location->lat),
            'lng' => self::coordinate($location->lng),
        ] : null;
    }

    private static function text(?string ...$parts): ?string
    {
        $text = collect($parts)->filter()->join(', ');

        return $text === '' ? null : $text;
    }

    private static function coordinate(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
