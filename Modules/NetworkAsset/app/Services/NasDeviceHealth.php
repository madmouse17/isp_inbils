<?php

namespace Modules\NetworkAsset\Services;

use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Notifications\NasDeviceUnreachableNotification;
use Spatie\Permission\Models\Permission;

/**
 * What the last contact with a NAS router says about it. Every job that talks to a router reports here, so a
 * reachable → unreachable transition is audited and notified exactly once, whoever noticed it first.
 *
 * `unreachable_since` is the start of the current outage; `last_checked_at` is the last attempt, which is
 * what the two-minute skip window (NetworkAccountProvisioner::skipReason) measures from.
 */
class NasDeviceHealth
{
    private const MANAGE_PERMISSION = 'network_asset.nas.manage';

    /**
     * The router answered. Clears an outage (audited) and keeps what the probe learned.
     *
     * @param  array{version: string, board_name: string, uptime: string}|null  $resource
     */
    public function reachable(NasDevice $nas, ?array $resource = null): void
    {
        $wasDown = $nas->unreachable_since !== null;

        $nas->forceFill([
            'last_checked_at' => now(),
            'last_seen_at' => now(),
            'last_error' => null,
            'unreachable_since' => null,
            ...($resource === null ? [] : ['routeros_version' => $resource['version'] === '' ? null : Str::limit($resource['version'], 64, '')]),
        ])->save();

        if ($wasDown) {
            AuditService::log('nas_device', 'recovered', ['network_asset_id' => $nas->network_asset_id], $nas);
        }
    }

    /** A call failed. Only a connection failure starts an outage; every kind is remembered as the last error. */
    public function failed(NasDevice $nas, RouterOsException $e): void
    {
        $startsOutage = $e->kind === RouterOsException::UNREACHABLE && $nas->unreachable_since === null;

        $nas->forceFill([
            'last_checked_at' => now(),
            'last_error' => Str::limit($e->getMessage(), 255, ''),
            'unreachable_since' => $startsOutage ? now() : $nas->unreachable_since,
        ])->save();

        if ($startsOutage) {
            AuditService::log('nas_device', 'unreachable', ['network_asset_id' => $nas->network_asset_id, 'error_kind' => $e->kind], $nas);
            $this->notifyManagers($nas, $e);
        }
    }

    private function notifyManagers(NasDevice $nas, RouterOsException $e): void
    {
        // Without the permission row (seeder not run yet) nobody can hold it.
        if (! Permission::query()->where('name', self::MANAGE_PERMISSION)->where('guard_name', 'web')->exists()) {
            return;
        }

        $managers = User::permission(self::MANAGE_PERMISSION)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => CompanyService::canActIn($user, (int) $nas->company_id));

        Notification::send($managers, new NasDeviceUnreachableNotification($nas->id, (string) $nas->networkAsset?->code, $e->kind));
    }
}
