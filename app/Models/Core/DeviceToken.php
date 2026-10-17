<?php

namespace App\Models\Core;

use App\Models\User;
use Database\Factories\DeviceTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The FCM registration token of one app install, bound to the user and to the Sanctum token that registered it.
 * Not company scoped: a token follows the user, whose company the notification already resolved. The token is
 * never serialized and never logged beyond its last characters.
 *
 * @property int $id
 * @property int $user_id
 * @property int $personal_access_token_id
 * @property string $app customer|technician
 * @property string $platform
 * @property string $token
 * @property string $token_hash
 * @property ?string $device_name
 * @property ?Carbon $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DeviceToken extends Model
{
    /** @use HasFactory<DeviceTokenFactory> */
    use HasFactory;

    public const PLATFORMS = ['android'];

    protected static string $factory = DeviceTokenFactory::class;

    protected $fillable = [
        'user_id', 'personal_access_token_id', 'app', 'platform', 'token', 'device_name', 'last_seen_at',
    ];

    protected $hidden = ['token', 'token_hash'];

    protected static function booted(): void
    {
        static::saving(fn (self $row) => $row->token_hash = self::hash($row->token));
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }

    /**
     * The installs a push may still reach: the Sanctum token that registered the row exists and has not expired
     * (the daily prune only deletes it a day later).
     *
     * @param  Builder<self>  $query
     */
    public function scopeReachable(Builder $query): void
    {
        $query->whereHas('personalAccessToken', fn (Builder $token) => $token
            ->where(fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now())));
    }

    /** The only part of the token that may appear in a log line. */
    public function suffix(): string
    {
        return substr($this->token, -6);
    }
}
