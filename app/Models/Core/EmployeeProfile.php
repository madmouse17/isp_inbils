<?php

namespace App\Models\Core;

use App\Models\User;
use App\Support\AuditProperties;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeProfile extends Model
{
    use BelongsToCompany {
        tapActivity as private stampCompany;
    }
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    /** The stored `status` values (the enum migration 2026_08_11_163004; `lib/statuses/people.ts` on screen). */
    public const STATUSES = ['active', 'inactive', 'terminated'];

    protected static string $factory = EmployeeProfileFactory::class;

    protected $fillable = [
        'company_id',
        'user_id',
        'organization_id',
        'vehicle_id',
        'employee_number',
        'phone',
        'hire_date',
        'status',
        'skills',
        'notes',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'skills' => 'array',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Karyawan';
    }

    public static function lifecycleFilters(): array
    {
        return self::statusFilters('status', self::STATUSES);
    }

    /** A profile keeps its own soft delete: Pulihkan brings it back, and its `user_id` and number stay reserved meanwhile. */
    public static function lifecycleReferences(): ?array
    {
        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<OrganizationUnit, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employee_profile')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** The diff of an employee profile says which personal field changed, never what it held. */
    public function tapActivity(ActivityContract $activity, string $eventName): void
    {
        $this->stampCompany($activity, $eventName);

        AuditProperties::maskValues($activity, ['phone', 'notes']);
    }
}
