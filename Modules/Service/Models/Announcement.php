<?php

namespace Modules\Service\Models;

use App\Models\Core\Location;
use App\Traits\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Service\Database\Factories\AnnouncementFactory;
use Modules\Service\Models\Concerns\HasPublicationStatus;

/**
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property string $body
 * @property string $kind
 * @property string $audience
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $notified_at
 * @property bool $is_active
 */
class Announcement extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicationStatus;

    public const AUDIENCES = ['all', 'targeted'];

    public const KINDS = ['info', 'outage', 'maintenance'];

    public const STATUSES = ['draft', 'scheduled', 'live', 'ended', 'inactive'];

    protected $fillable = [
        'title',
        'body',
        'kind',
        'audience',
        'starts_at',
        'ends_at',
    ];

    protected $attributes = [
        'audience' => 'all',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'announcement_location')->withTrashed();
    }

    /** @return BelongsToMany<ServicePackageCategory, $this> */
    public function servicePackageCategories(): BelongsToMany
    {
        return $this->belongsToMany(ServicePackageCategory::class, 'announcement_service_package_category');
    }

    protected static function newFactory(): AnnouncementFactory
    {
        return AnnouncementFactory::new();
    }
}
