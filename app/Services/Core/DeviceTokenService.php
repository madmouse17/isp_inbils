<?php

namespace App\Services\Core;

use App\Models\Core\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Registers and forgets the FCM tokens of the two mobile apps. The token is the identity of an install, so the
 * last user to register it owns it: a phone signed in as someone else moves the token to that user and to the
 * Sanctum token they hold now, and the previous user stops receiving pushes on it.
 */
final class DeviceTokenService
{
    /**
     * Upserts by token and binds the row to $access, so revoking or losing that Sanctum token silences the install.
     *
     * @return array{0: DeviceToken, 1: bool} the row, and whether it is new
     */
    public function register(User $user, PersonalAccessToken $access, string $app, string $token, ?string $deviceName): array
    {
        $values = [
            'user_id' => $user->getKey(),
            'personal_access_token_id' => $access->getKey(),
            'app' => $app,
            'platform' => 'android',
            'device_name' => $deviceName ?? $access->name,
            'last_seen_at' => now(),
        ];

        $existing = $this->matching($token)->first();

        try {
            $row = ($existing ?? new DeviceToken(['token' => $token]))->fill($values);
            $row->save();

            return [$row, $existing === null];
        } catch (UniqueConstraintViolationException) {
            // A concurrent request registered the same token after the lookup: that row is the one to update.
            $row = $this->matching($token)->firstOrFail()->fill($values);
            $row->save();

            return [$row, false];
        }
    }

    /** Only the user's own row goes: a token held by someone else, or by nobody, is a no-op. */
    public function forget(User $user, string $token): void
    {
        $this->matching($token)->where('user_id', $user->getKey())->delete();
    }

    /** @return Builder<DeviceToken> */
    private function matching(string $token): Builder
    {
        return DeviceToken::query()->where('token_hash', DeviceToken::hash($token));
    }
}
