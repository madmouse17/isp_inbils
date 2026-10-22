<?php

namespace Modules\NetworkAsset\Http\Resources;

use App\Services\Core\CompanyService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * Never carries the RADIUS shared secret: only `has_radius_secret`. The shortname is the realm of the current
 * company (every row of a page belongs to it), so a page costs no query per row.
 *
 * `radius_state` and `last_accounting_at` say what the RADIUS server knows about the router's traffic. Reading that is
 * one query per router, so only the page of one router asks (withLastAccounting()); a list leaves both null.
 *
 * @mixin NasDevice
 */
class NasDeviceResource extends JsonResource
{
    private bool $trafficRead = false;

    private ?CarbonInterface $lastAccountingAt = null;

    /** The newest accounting packet of the router as RADIUS holds it; null when it never sent one. */
    public function withLastAccounting(?CarbonInterface $lastAccountingAt): static
    {
        $this->trafficRead = true;
        $this->lastAccountingAt = $lastAccountingAt;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $asset = $this->relationLoaded('networkAsset') ? $this->networkAsset : null;
        $location = $asset?->relationLoaded('location') ? $asset->location : null;

        return [
            'id' => $this->public_id,
            'network_asset' => $asset === null ? null : [
                'id' => $asset->public_id,
                'code' => $asset->code,
                'name' => $asset->name,
                'management_ip' => $asset->getAttribute('management_ip'),
                'location' => $location === null ? null : ['id' => $location->public_id, 'name' => $location->getAttribute('name'), 'type' => $location->getAttribute('type')],
            ],
            'nasname' => (string) $this->nasname,
            'shortname' => (string) Realm::fromCode(CompanyService::current()?->code),
            'coa_port' => $this->coa_port,
            'has_radius_secret' => filled($this->resource->getRawOriginal('radius_secret')),
            'radius_synced_at' => $this->radius_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'radius_state' => $this->radiusState(),
            'last_accounting_at' => $this->lastAccountingAt?->toIso8601String(),
        ];
    }

    /**
     * `not_synced`: no client row was written yet. `awaiting_restart`: it was written and the router has sent nothing
     * since, which is how a FreeRADIUS that has not been restarted looks (it reads its clients only when it starts).
     * `live`: a packet newer than the write arrived. Null while RADIUS was not asked or did not answer.
     */
    private function radiusState(): ?string
    {
        if (! $this->trafficRead) {
            return null;
        }

        if ($this->radius_synced_at === null) {
            return 'not_synced';
        }

        return $this->lastAccountingAt?->gte($this->radius_synced_at) === true ? 'live' : 'awaiting_restart';
    }
}
