<?php

namespace App\Models\Core;

use App\Services\Core\OrganizationService;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Database\Factories\OrganizationUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OrganizationUnit extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected static string $factory = OrganizationUnitFactory::class;

    protected static function booted(): void
    {
        // A unit deleted before an ancestor was renamed or moved comes back under its parent's current path.
        static::restored(fn (self $unit) => OrganizationService::recomputePath($unit));
    }

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'path',
        'address',
        'phone',
        'email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Unit organisasi';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** `work_orders.organization_unit_id` is stamped when an SPK completes (SpkService::approve). */
    public static function lifecycleReferences(): ?array
    {
        return [
            'sub-unit' => ['organization_units.parent_id'],
            'karyawan' => ['employee_profiles.organization_id'],
            'SPK selesai' => ['work_orders.organization_unit_id'],
        ];
    }

    /** @return BelongsTo<OrganizationUnit, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class, 'organization_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization_unit')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
