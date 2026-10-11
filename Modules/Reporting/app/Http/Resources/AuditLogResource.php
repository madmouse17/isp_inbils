<?php

namespace Modules\Reporting\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * @property Activity $resource
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canViewProperties = $request->user()?->can('report.audit.view') ?? false;
        /** @var Collection|null $props */
        $props = $this->resource->properties ?? null;
        $properties = ($canViewProperties && $props) ? $props->toArray() : [];

        $causer = $this->resource->causer;

        return [
            'id' => $this->resource->id,
            'company_id' => $this->resource->getAttribute('company_id'),
            'log_name' => $this->resource->log_name,
            'description' => $this->resource->description,
            'event' => $this->resource->event,
            'causer_id' => $this->resource->causer_id,
            'causer_type' => $this->resource->causer_type,
            'causer' => $causer instanceof User ? [
                'id' => $causer->id,
                'name' => $causer->name,
                'email' => $causer->email,
            ] : null,
            'subject_type' => $this->resource->subject_type,
            'subject_id' => $this->resource->subject_id,
            'properties' => $properties,
            'created_at' => $this->resource->created_at?->toISOString() ?? (string) $this->resource->created_at,
        ];
    }
}
