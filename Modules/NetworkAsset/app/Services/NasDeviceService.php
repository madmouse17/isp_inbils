<?php

namespace Modules\NetworkAsset\Services;

use App\Services\Core\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;

/**
 * Registering, changing and removing NAS routers. Every method acts in the current company (the models
 * fail closed without one) and audits without ever writing a password: a new one shows up only as
 * `credentials_changed`.
 */
class NasDeviceService
{
    /**
     * @param  array{network_asset_id: int, api_port?: int, api_username: string, api_password: string, isolir_profile?: string, provisioning_enabled?: bool}  $data
     */
    public static function create(array $data): NasDevice
    {
        return DB::transaction(function () use ($data): NasDevice {
            $asset = self::routerAsset((int) $data['network_asset_id']);

            abort_if(NasDevice::query()->where('network_asset_id', $asset->id)->exists(), 422, 'This router asset is already registered as a NAS router.');

            $nas = NasDevice::query()->create([
                'network_asset_id' => $asset->id,
                'api_port' => $data['api_port'] ?? 443,
                'api_username' => $data['api_username'],
                'api_password' => $data['api_password'],
                'isolir_profile' => $data['isolir_profile'] ?? 'isolir',
                'provisioning_enabled' => (bool) ($data['provisioning_enabled'] ?? false),
            ]);

            AuditService::log('nas_device', 'created', ['network_asset_id' => $asset->id, 'asset_code' => $asset->code], $nas);

            return $nas;
        });
    }

    /**
     * A blank password keeps the stored one. The router asset cannot be changed.
     *
     * @param  array{api_port?: int, api_username?: string, api_password?: ?string, isolir_profile?: string, provisioning_enabled?: bool}  $data
     */
    public static function update(NasDevice $nas, array $data): NasDevice
    {
        return DB::transaction(function () use ($nas, $data): NasDevice {
            $password = $data['api_password'] ?? null;
            unset($data['api_password']);

            $nas->fill($data);
            $credentialsChanged = is_string($password) && $password !== '';

            if ($credentialsChanged) {
                $nas->api_password = $password;
            }

            $changed = array_values(array_diff(array_keys($nas->getDirty()), ['api_password']));

            if ($changed === [] && ! $credentialsChanged) {
                return $nas;
            }

            $nas->save();

            AuditService::log('nas_device', 'updated', ['changed' => $changed, 'credentials_changed' => $credentialsChanged], $nas);

            return $nas;
        });
    }

    /** Refused while any network account is, or was last provisioned, on the router. */
    public static function delete(NasDevice $nas): void
    {
        DB::transaction(function () use ($nas): void {
            if (NetworkAccount::query()->where(fn ($query) => $query->where('nas_device_id', $nas->id)->orWhere('provisioned_nas_device_id', $nas->id))->exists()) {
                throw ValidationException::withMessages(['nas_device' => 'This NAS router still has network accounts. Move or finish them first.']);
            }

            $code = $nas->networkAsset?->code;
            $nas->delete();

            AuditService::log('nas_device', 'deleted', ['network_asset_id' => $nas->network_asset_id, 'asset_code' => $code], $nas);
        });
    }

    /** A router asset of the current company that has a management address. */
    private static function routerAsset(int $id): NetworkAsset
    {
        $asset = NetworkAsset::query()->whereKey($id)->where('asset_type', 'router')->first();

        abort_if($asset === null, 422, 'Choose a router asset of this company.');
        abort_if(blank($asset->getAttribute('management_ip')), 422, 'The router asset has no management IP.');

        return $asset;
    }
}
