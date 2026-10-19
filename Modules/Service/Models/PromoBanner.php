<?php

namespace Modules\Service\Models;

use App\Models\Core\Location;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Service\Database\Factories\PromoBannerFactory;
use Modules\Service\Models\Concerns\HasPublicationStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\File;

/**
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property string $alt_text
 * @property string $link_kind
 * @property string|null $link_target
 * @property string $audience
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $published_at
 * @property int $sort_order
 * @property bool $is_active
 * @property int $tap_count
 */
class PromoBanner extends Model implements HasMedia
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicationStatus;
    use HasPublicId;
    use InteractsWithMedia;

    public const APP_ROUTES = ['invoices', 'subscriptions', 'ticket_create', 'visits'];

    protected $fillable = [
        'title',
        'alt_text',
        'link_kind',
        'link_target',
        'audience',
        'starts_at',
        'ends_at',
        'sort_order',
    ];

    protected $attributes = [
        'link_kind' => 'none',
        'audience' => 'all',
        'sort_order' => 0,
        'is_active' => true,
        'tap_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'tap_count' => 'integer',
        ];
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_promo_banner')->withTrashed();
    }

    /** @return BelongsToMany<ServicePackageCategory, $this> */
    public function servicePackageCategories(): BelongsToMany
    {
        return $this->belongsToMany(ServicePackageCategory::class, 'promo_banner_service_package_category');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('promo_banner')
            ->acceptsFile(fn (File $file): bool => in_array($file->mimeType, ['image/jpeg', 'image/png', 'image/webp'], true));
    }

    protected static function newFactory(): PromoBannerFactory
    {
        return PromoBannerFactory::new();
    }
}
