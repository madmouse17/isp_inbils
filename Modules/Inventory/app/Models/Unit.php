<?php

namespace Modules\Inventory\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Inventory\Database\Factories\UnitFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Unit extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    /** The column default, so that a unit that was just created reads as active without a refresh. */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'symbol',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Satuan';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    public static function lifecycleReferences(): ?array
    {
        return [
            'barang' => ['products.unit_id'],
            'kategori barang' => ['categories.unit_id'],
        ];
    }

    /**
     * The exists rule for the unit a form gives a record: an active unit of the company, or the one the record already
     * holds, so saving it never fails over a unit that was deactivated afterwards.
     */
    public static function assignableRule(int $companyId, ?int $heldUnitId = null): Exists
    {
        return Rule::exists('units', 'id')->where(fn (Builder $query) => $query
            ->where('company_id', $companyId)
            ->where(fn (Builder $unit) => $unit
                ->where(fn (Builder $active) => $active->where('is_active', true)->whereNull('deleted_at'))
                ->orWhere('id', $heldUnitId)));
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('unit')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }
}
