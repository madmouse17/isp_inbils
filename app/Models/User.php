<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Services\Core\ApiTokenService;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Support\AuditProperties;
use App\Traits\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        // The link to the customer record goes with the row (customers.user_id is nullOnDelete), before the `deleted` audit row
        // is written: read the kind of account while the link still exists, or the row would show the contact person's name.
        static::deleting(function (User $user): void {
            $user->isCustomerAccount = $user->isCustomerAccount || $user->isLinkedToCustomer();
        });
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

    /**
     * The ids of the companies the user may act in: his home company and every granted membership. Read from the
     * `companies` relation, so a page of users loads the memberships of all its rows with one query. The authorization
     * of a page needs this for every row; CompanyMembershipService::companyIdsOf() asks the table instead, for the writes
     * that must see the memberships as they are now.
     *
     * @return Collection<int, int>
     */
    public function companyIds(): Collection
    {
        return $this->companies->pluck('id')->push($this->company_id)->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();
    }

    /**
     * The user's roles in EVERY company, not only the one in effect. A role belongs to one company (ADR-004) and `roles`
     * follows the company the request acts in, so judging a user by what he holds elsewhere used to switch company and
     * reload `roles` for each of them. This relation holds them all at once, and a page of users loads it for all its
     * rows with one query (`rolesInEveryCompany.permissions`). Read it with permissionNamesIn().
     *
     * @return BelongsToMany<Role, $this>
     */
    public function rolesInEveryCompany(): BelongsToMany
    {
        return $this->roles()->withoutGlobalScope('company');
    }

    /**
     * The permissions the user holds in a company, by name: his direct permissions (they apply in every company) and
     * those of his roles of that company. The answer getAllPermissions() gives while acting as that company, read from
     * `rolesInEveryCompany` and `permissions`, so asking for another company costs no reload.
     *
     * @return list<string>
     */
    public function permissionNamesIn(int $companyId): array
    {
        return $this->permissions->pluck('name')
            ->merge($this->rolesInEveryCompany
                ->where('company_id', $companyId)
                ->flatMap(fn (Role $role) => $role->permissions->pluck('name')))
            ->unique()
            ->values()
            ->all();
    }

    /** Set by CustomerService for the account it creates, before the link to its customer record exists, and on deleting, while it still does. */
    public bool $isCustomerAccount = false;

    /**
     * The company the `roles` relation was loaded for. Roles are per company, and one instance outlives a company switch,
     * a runAs() or a queue job that restores it before the job enters its company: a relation loaded for another
     * company would answer for the wrong roles (ADR-004).
     */
    private ?int $rolesLoadedFor = null;

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * Users who hold one of the permissions in the company: through one of its roles, or directly (direct permissions
     * belong to the user, in every company). It names the company, so it works where nobody is signed in (a webhook,
     * a job, the scheduler); the `roles` relation follows the ambient company and would match nobody there.
     *
     * @param  Builder<User>  $query
     * @param  string|list<string>  $permissions
     * @return Builder<User>
     */
    public function scopeWithPermissionIn(Builder $query, string|array $permissions, int $companyId): Builder
    {
        $names = Arr::wrap($permissions);

        return $query->where(fn (Builder $holders) => $holders
            ->whereHas('permissions', fn (Builder $permission) => $permission->whereIn('permissions.name', $names))
            ->orWhereHas('roles', fn (Builder $role) => $role
                ->withoutGlobalScope('company')
                ->where('roles.company_id', $companyId)
                ->whereHas('permissions', fn (Builder $permission) => $permission->whereIn('permissions.name', $names))));
    }

    /** A `roles` relation loaded for another company is dropped, so every role and permission check reloads it. */
    public function relationLoaded($key): bool
    {
        if ($key === 'roles' && parent::relationLoaded('roles') && $this->rolesLoadedFor !== CompanyService::currentId()) {
            // Not unsetRelation(): `rolesInEveryCompany` does not follow the company in effect, so it stays loaded.
            parent::unsetRelation('roles');
        }

        return parent::relationLoaded($key);
    }

    /** The package unsets `roles` after every assign and remove; `rolesInEveryCompany` holds the same assignments, so it goes with it. */
    public function unsetRelation($relation): static
    {
        if ($relation === 'roles') {
            parent::unsetRelation('rolesInEveryCompany');
        }

        return parent::unsetRelation($relation);
    }

    public function setRelation($relation, $value): static
    {
        if ($relation === 'roles') {
            $this->rolesLoadedFor = CompanyService::currentId();
        }

        return parent::setRelation($relation, $value);
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
     * stay readable: the causer column answers who changed what. A customer account is told by its link to a customer
     * record, which exists in every company context; CustomerService flags the account for its `created` row, because
     * the link is made after the first save, and the `deleting` hook for its `deleted` row, because the link is gone by then.
     */
    public function tapActivity(ActivityContract $activity, string $eventName): void
    {
        $customerAccount = $this->isCustomerAccount || $this->isLinkedToCustomer();

        AuditProperties::maskValues($activity, $customerAccount ? ['email', 'name'] : ['email']);
    }

    private function isLinkedToCustomer(): bool
    {
        return Customer::withoutCompany()->withTrashed()->where('user_id', $this->id)->exists();
    }
}
