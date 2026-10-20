<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Services\Core\ApiTokenService;
use App\Services\Core\AuditService;
use App\Support\AuditProperties;
use App\Traits\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * Typed here because the encrypted and date casts of the two-factor columns are not inferred.
 *
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_step
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, LogsActivity, Notifiable;

    /**
     * What makes the daily warehouse digest (`inventory_digest`) worth offering: whoever holds one of these has something
     * to act on in it. Read by the command that sends it and by the profile page that offers the switch.
     *
     * @var list<string>
     */
    public const INVENTORY_DIGEST_PERMISSIONS = [
        'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue',
        'inventory.return.receive', 'inventory.return.resolve',
    ];

    protected static $recordEvents = ['created', 'updated', 'deleted'];

    /**
     * A lost phone must not outlive the credential or the account, so the app tokens go whenever the password
     * changes, the user is deactivated or deleted. The hook sits on the model so every writer is covered: the
     * reset, the profile form, the admin form and anything that saves a User later. A changed password also
     * leaves a security row (ids only, never the value), whoever wrote it.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('password')) {
                AuditService::log('security', 'password.changed', ['user_id' => $user->id], $user);
            }

            $reason = match (true) {
                $user->wasChanged('password') => 'password_changed',
                $user->wasChanged('is_active') && ! $user->is_active => 'deactivated',
                default => null,
            };

            if ($reason !== null) {
                app(ApiTokenService::class)->revokeAll($user, $reason);
            }
        });

        static::deleted(fn (User $user) => app(ApiTokenService::class)->revokeAll($user, 'deleted'));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_used_step',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'inventory_digest' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
        ];
    }

    /** The home company: where the user lands at login. Membership grants access to others. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Companies the user was granted membership of. The home company counts as a member even
     * without a row here; CompanyService::isActiveMember() is the authority.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('is_default')->withTimestamps();
    }

    /** Set by CustomerService for the account it creates, before the customer role exists. */
    public bool $isCustomerAccount = false;

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /** Two-factor authentication is on once the first code was confirmed; a bare secret is a pending enrolment. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // The two-factor columns hold secrets or per-login state; they are written by TwoFactorService,
        // which audits the event itself without any secret material.
        return LogOptions::defaults()
            ->useLogName('user')
            ->logAll()
            ->logExcept([
                'public_id',
                'password',
                'remember_token',
                'email_verified_at',
                'last_login_at',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_used_step',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The diff of a user hides the address; a customer's name is the contact person, so it is hidden too. Staff names
     * stay readable: the causer column answers who changed what. The role is granted after the first save, so
     * CustomerService flags the account for its `created` row.
     */
    public function tapActivity(ActivityContract $activity, string $eventName): void
    {
        AuditProperties::maskValues($activity, $this->isCustomerAccount || $this->hasRole('customer') ? ['email', 'name'] : ['email']);
    }
}
