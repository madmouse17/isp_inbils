<?php

namespace Modules\NetworkAsset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Queries\AssetPanelQuery;

/**
 * The wire payload of the hardware panel (design §4.3; the TypeScript side is resources/js/types/hardware-panel.ts).
 * It wraps the facts of AssetPanelQuery::for() and keeps the size budget (30 KB for a 128-port OLT) by leaving out
 * what the page does not draw: false flags, absent optional keys, and everything about a port that only its detail
 * needs (customer, subscription, note). `id: null` stays: it marks a slot without a register row.
 */
final class AssetPanelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $facts = $this->resource;

        return [
            'asset' => $facts['asset'],
            'template' => $facts['template'],
            'groups' => array_map(self::group(...), $facts['groups']),
            'online' => array_filter($facts['online'], fn (mixed $value): bool => $value !== null),
            ...($facts['picker'] === null ? [] : ['picker' => $facts['picker']]),
            ...($facts['rack'] === null ? [] : ['rack' => $facts['rack']]),
            ...($facts['odn_hidden'] ? ['odn_hidden' => true] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private static function group(array $group): array
    {
        return [...$group, 'ports' => array_map(self::port(...), $group['ports'])];
    }

    /**
     * @param  array<string, mixed>  $port
     * @return array<string, mixed>
     */
    private static function port(array $port): array
    {
        $port['state'] = AssetPanelQuery::flags($port['state']);

        return array_filter($port, fn (mixed $value, string $key): bool => $value !== null || $key === 'id', ARRAY_FILTER_USE_BOTH);
    }
}
