<?php

namespace App\Models\Core;

use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Core\CompanyService;
use App\Traits\HasPublicId;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property Carbon|null $suspended_at set by TenantLifecycleService when the platform suspends the tenant
 * @property Carbon|null $deletion_requested_at set by TenantLifecycleService once a deletion was requested
 */
class Company extends Model implements HasMedia
{
    use HasFactory;
    use HasPublicId;
    use InteractsWithMedia;
    use LogsActivity;

    protected static string $factory = CompanyFactory::class;

    protected static $recordEvents = ['created', 'updated'];

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'whatsapp',
        'email',
        'website',
        'brand_color',
        'support_hours',
        'timezone',
        'currency',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        // Platform lifecycle, written only by TenantLifecycleService: not fillable.
        'suspended_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
    ];

    /**
     * Membership checks are memoised per request, so a flip of is_active drops the memo. A suspended company must
     * not keep a phone signed in either: the app tokens of the users it strands go with it.
     */
    protected static function booted(): void
    {
        static::updated(function (Company $company): void {
            if (! $company->wasChanged('is_active')) {
                return;
            }

            CompanyService::forgetResolution();

            if (! $company->is_active) {
                app(ApiTokenService::class)->revokeForSuspendedCompany($company);
            }
        });
    }

    /**
     * `{company}` in the platform panel is a tenant: a top-level company. The id of a subsidiary does not resolve (the
     * route answers 404), so no lifecycle action can target one. A binding by another key keeps every company.
     * Here and not in a route file, so that `route:cache` keeps it.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->newQuery()->whereNull('parent_company_id')->where($this->getRouteKeyName(), $value)->first();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->useDisk('public');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The Head of this subsidiary. Not mass assignable: CompanyHierarchyService owns the hierarchy.
     *
     * @return BelongsTo<Company, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_company_id');
    }

    /** @return HasMany<Company, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_company_id');
    }

    /**
     * Users that may act in this company: the home-company memberships and the granted ones.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_default')->withTimestamps();
    }

    /** A Head is a company without a parent that has at least one subsidiary. */
    public function isHead(): bool
    {
        // Only active subsidiaries count: a Head without one has nothing to consolidate.
        return $this->parent_company_id === null && $this->children()->where('is_active', true)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('company')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
