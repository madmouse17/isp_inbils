<?php

namespace Modules\NetworkAsset\Services;

use App\Services\Core\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Jobs\WriteNasDeviceJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;

/**
 * Registering, changing and removing NAS routers (FreeRADIUS clients). Every method acts in the current company
 * (the models fail closed without one). The Inbils row is saved first; the RADIUS write runs once that
 * transaction is closed and never throws: a failure is left on the row (`last_error`, `radius_synced_at` null)
 * for `network:sync` to retry. Audit entries never carry the shared secret.
 */
class NasDeviceService
{
    /**
     * `new_asset` makes the router asset in the same transaction (F2-U08), so a router that was never entered as an
     * asset needs no second form; when either part fails neither is kept.
     *
     * @param  array{network_asset_id?: int, new_asset?: array{name: string, management_ip: string}, nasname?: ?string, coa_port?: ?int, radius_secret: string}  $data
     */
    public static function create(array $data): NasDevice
    {
        $nas = DB::transaction(function () use ($data): NasDevice {
            $asset = isset($data['new_asset']) ? self::createRouterAsset($data['new_asset']) : self::routerAsset((int) $data['network_asset_id']);

            abort_if(NasDevice::query()->where('network_asset_id', $asset->id)->exists(), 422, 'Aset router ini sudah terdaftar sebagai router NAS.');

            try {
                $nas = NasDevice::query()->create([
                    'network_asset_id' => $asset->id,
                    'nasname' => $data['nasname'] ?? $asset->getAttribute('management_ip'),
                    'coa_port' => $data['coa_port'] ?? 1700,
                    'radius_secret' => $data['radius_secret'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // A parallel create passed the same pre-check: answer as the pre-check or the request rule would.
                abort_if(NasDevice::query()->where('network_asset_id', $asset->id)->exists(), 422, 'Aset router ini sudah terdaftar sebagai router NAS.');
                abort(422, 'Alamat ini tidak tersedia.');
            }

            AuditService::log('nas_device', 'created', ['network_asset_id' => $asset->id, 'asset_code' => $asset->code, 'nasname' => $nas->nasname], $nas);

            return $nas;
        });

        app(NetworkAccountProvisioner::class)->writeNas($nas);

        return $nas;
    }

    /**
     * A blank secret keeps the stored one. The router asset cannot be changed. RADIUS is written only when the
     * address or the secret changed.
     *
     * @param  array{nasname?: string, coa_port?: int, radius_secret?: ?string}  $data
     */
    public static function update(NasDevice $nas, array $data): NasDevice
    {
        $secret = $data['radius_secret'] ?? null;
        $secretChanged = is_string($secret) && $secret !== '' && $secret !== $nas->radius_secret;

        $writeRadius = DB::transaction(function () use ($nas, $data, $secret, $secretChanged): bool {
            $nas->fill(array_diff_key($data, ['radius_secret' => true]));
            $changed = array_keys($nas->getDirty());

            if ($secretChanged) {
                $nas->radius_secret = $secret;
            }

            if ($changed === [] && ! $secretChanged) {
                return false;
            }

            $nas->save();

            if ($changed !== []) {
                AuditService::log('nas_device', 'updated', ['changed' => $changed], $nas);
            }

            if ($secretChanged) {
                AuditService::log('nas_device', 'secret_changed', ['asset_code' => $nas->networkAsset?->code], $nas);
            }

            return $secretChanged || in_array('nasname', $changed, true);
        });

        if ($writeRadius) {
            app(NetworkAccountProvisioner::class)->writeNas($nas);
        }

        return $nas;
    }

    /**
     * Allowed while accounts or sessions exist: the router ends its sessions on its own terms.
     *
     * @return bool false when the RADIUS client row could not be removed (switch off or database down); it then stays until someone removes it
     */
    public static function delete(NasDevice $nas): bool
    {
        $nasname = $nas->radius_nasname;

        DB::transaction(function () use ($nas): void {
            $code = $nas->networkAsset?->code;
            $nas->delete();

            AuditService::log('nas_device', 'deleted', ['network_asset_id' => $nas->network_asset_id, 'asset_code' => $code], $nas);
        });

        if ($nasname === null) {
            return true;
        }

        try {
            app(RadiusDirectory::class)->removeNas($nasname);
        } catch (RadiusException $e) {
            AuditService::log('nas_device', 'radius_failed', ['nasname' => $nasname, 'error_kind' => $e->kind], $nas);

            return false;
        }

        return true;
    }

    /**
     * The "Sinkronkan ke RADIUS" button: a queued job writes the router now, ahead of the 15-minute sweep. The audit
     * row names the router and never a secret. The caller checks that the company has RADIUS before it asks.
     */
    public static function requestSync(NasDevice $nas): void
    {
        AuditService::log('nas_device', 'sync_requested', ['asset_code' => $nas->networkAsset?->code], $nas);

        WriteNasDeviceJob::dispatch((int) $nas->company_id, $nas->id);
    }

    /** A router asset of the current company that has a management address. */
    private static function routerAsset(int $id): NetworkAsset
    {
        $asset = NetworkAsset::query()->whereKey($id)->where('asset_type', 'router')->first();

        abort_if($asset === null, 422, 'Pilih aset router milik perusahaan ini.');
        abort_if(blank($asset->getAttribute('management_ip')), 422, 'Aset router belum punya IP manajemen.');

        return $asset;
    }

    /**
     * A router asset made by the NAS form: coded like any asset, its management address claimed in IPAM like the asset
     * form does. IPAM's refusal ("sudah dipakai ...") is shown under the field of this form.
     *
     * @param  array{name: string, management_ip: string}  $router
     */
    private static function createRouterAsset(array $router): NetworkAsset
    {
        $asset = NetworkAsset::query()->create([
            'code' => NetworkAssetService::generateCode(),
            'name' => $router['name'],
            'asset_type' => 'router',
            'management_ip' => $router['management_ip'],
            'status' => 'available',
        ]);

        try {
            IpAssignmentService::syncAssetAddresses($asset);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $field): array => ["new_asset.{$field}" => $messages])->all());
        }

        return $asset;
    }
}
