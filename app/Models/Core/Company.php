<?php

namespace App\Models\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Company extends Model
{
    use HasFactory;
    use LogsActivity;

    protected static string $factory = \Database\Factories\CompanyFactory::class;

    protected static $recordEvents = ['created', 'updated'];

    protected $fillable = [
        'name',
        'code',
        'logo',
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

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
