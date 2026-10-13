<?php

namespace App\Models\Core;

use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Core\CompanyService;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Company extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use LogsActivity;

    protected static string $factory = CompanyFactory::class;

    protected static $recordEvents = ['created', 'updated'];

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'email',
        'website',
        'timezone',
        'currency',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
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
