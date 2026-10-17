<?php

namespace Modules\Reporting\Http\Resources;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\AuditProperties;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * @property Activity $resource
 */
class AuditLogResource extends JsonResource
{
    /** An entry keeps the integer ids of its company and its subject: the page reads both records once per type. */
    public static function collection($resource): AnonymousResourceCollection
    {
        /** @var EloquentCollection<int, Activity> $activities */
        $activities = new EloquentCollection($resource instanceof AbstractPaginator ? $resource->items() : collect($resource)->all());
        $companies = Company::query()
            ->whereIn('id', $activities->map(fn (Activity $activity): mixed => $activity->getAttribute('company_id'))->filter()->unique()->values())
            ->get(['id', 'public_id'])
            ->keyBy('id');

        $activities->each(fn (Activity $activity) => $activity->setRelation('company', $companies->get($activity->getAttribute('company_id'))));
        self::loadSubjects($activities);

        return parent::collection($resource);
    }

    /**
     * The subject of every entry whose type is still a model, read once per type; a deleted subject keeps its id.
     *
     * @param  EloquentCollection<int, Activity>  $activities
     */
    public static function loadSubjects(EloquentCollection $activities): void
    {
        (new EloquentCollection($activities->filter(fn (Activity $activity): bool => is_string($activity->subject_type) && is_subclass_of($activity->subject_type, Model::class))->all()))
            ->loadMissing(['subject' => fn (MorphTo $subject) => $subject->withTrashed()]);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canViewProperties = $request->user()?->can('report.audit.view') ?? false;
        /** @var Collection|null $props */
        $props = $this->resource->properties ?? null;
        $properties = ($canViewProperties && $props) ? AuditProperties::forDisplay($props->toArray()) : [];

        $causer = $this->resource->causer;
        $platformCauser = $causer instanceof PlatformAdmin;

        return [
            'id' => $this->resource->public_id,
            'company_id' => $this->resource->relationLoaded('company') ? $this->resource->getRelation('company')?->public_id : null,
            'log_name' => $this->resource->log_name,
            'description' => $this->resource->description,
            'event' => $this->resource->event,
            'causer_id' => $causer instanceof User ? $causer->public_id : null,
            'causer_type' => $this->resource->causer_type,
            'causer' => $platformCauser ? [
                'id' => null,
                'name' => 'Tim dukungan Inbils',
                'email' => null,
            ] : ($causer instanceof User ? [
                'id' => $causer->public_id,
                'name' => $causer->name,
                'email' => $causer->email,
            ] : null),
            'subject_type' => $this->resource->subject_type,
            'subject_id' => $this->resource->relationLoaded('subject') ? $this->resource->subject?->getAttribute('public_id') : null,
            'properties' => $properties,
            'created_at' => $this->resource->created_at?->toISOString() ?? (string) $this->resource->created_at,
        ];
    }
}
