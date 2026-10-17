<?php

namespace App\Models;

use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * An operator of the platform panel (/platform), on its own table and the `platform` session guard.
 *
 * Never a tenant user: no company, no membership, no tenant role, no Sanctum token and no remember-me (an empty
 * remember token name turns every remember-token call into a no-op). Access is all or nothing; the only
 * permission boundary is being an active platform admin who passed two-factor authentication in this session.
 *
 * @property string $name
 * @property string $email
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_step
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class PlatformAdmin extends Authenticatable
{
    /** @use HasFactory<PlatformAdminFactory> */
    use HasFactory;

    protected $rememberTokenName = '';

    /**
     * Not fillable: the two-factor columns, is_active and last_login_at are written by TwoFactorService, the
     * login flow and the console command.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_used_step',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Two-factor authentication is on once the first code was confirmed; a bare secret is a pending enrolment. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
