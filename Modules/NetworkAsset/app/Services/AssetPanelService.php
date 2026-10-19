<?php

namespace Modules\NetworkAsset\Services;

use App\Services\Core\AuditService;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Models\PortTemplate;
use Modules\NetworkAsset\Support\PanelLayout;

/**
 * The write side of the hardware panel (spec .scratch/hardware-panel-ui, design §4.7): the model of a device, the
 * label and links of its ports, and its place in a rack. PON ports and splitter outputs stay in the ODN register;
 * this creates them through OdnService and never copies them.
 *
 * Every write that touches two rows locks them in id order inside one transaction. A refusal is a
 * ValidationException on the field the form shows it under.
 */
final class AssetPanelService
{
    /** OdnService::createPonPorts takes 1 to 64 ports per call. */
    private const PON_CHUNK = 64;

    /**
     * Gives the asset a catalogue model ($templateKey) or a custom layout, or sets a rack's size in U ($heightU).
     * Ports that the old and the new layout share keep their label, note, state and link; ports the new layout drops
     * are deleted while free and refused by name while linked. Applying what is already there changes nothing.
     * Callers refresh() the asset afterwards.
     *
     * @param  array<string, mixed>|null  $layout
     */
    public static function apply(NetworkAsset $asset, ?string $templateKey, ?array $layout, ?int $heightU): void
    {
        RecordNotFound::abortUnless(in_array($asset->asset_type, PanelLayout::ASSET_TYPES, true));

        // One apply per asset at a time: two of them would both see "no splitter yet" or "16 PON ports short".
        self::exclusive($asset, function () use ($asset, $templateKey, $layout, $heightU): void {
            if ($asset->asset_type === 'rack') {
                self::resizeRack($asset, $templateKey, $layout, $heightU);

                return;
            }

            [$template, $layout] = self::resolve($asset, $templateKey, $layout);

            // Before the transaction and its row locks: OdnService takes the topology lock first, and so must we.
            self::registerOdn($asset, $layout, $template === null ? 'layout' : 'template_key');
            self::reconcile($asset, $template, $layout, $heightU);
        });
    }

    /**
     * Label, note and admin state of a port; the keys that are present are applied.
     *
     * @param  array{label?: string|null, note?: string|null, admin_down?: bool}  $data
     */
    public static function updatePort(AssetPort $port, array $data): AssetPort
    {
        $port->update(Arr::only($data, ['label', 'note', 'admin_down']));

        return $port;
    }

    /** Patches two free ports of two different assets of the company together; the link is written to both rows. */
    public static function link(AssetPort $port, int $targetPortId): void
    {
        if ($port->id === $targetPortId) {
            throw ValidationException::withMessages(['target_port_id' => 'Port tidak dapat dihubungkan ke dirinya sendiri.']);
        }

        DB::transaction(function () use ($port, $targetPortId): void {
            $ports = self::lockPorts([$port->id, $targetPortId]);
            $source = $ports[$port->id] ?? throw ValidationException::withMessages(['port' => 'Port tidak ditemukan.']);
            $target = $ports[$targetPortId] ?? throw ValidationException::withMessages(['target_port_id' => 'Port yang dipilih tidak ditemukan.']);

            if ($source->network_asset_id === $target->network_asset_id) {
                throw ValidationException::withMessages(['target_port_id' => 'Hubungkan ke port perangkat lain.']);
            }

            // The ports of a deleted asset stay behind; they are no target.
            if (! NetworkAsset::query()->whereKey($target->network_asset_id)->exists()) {
                throw ValidationException::withMessages(['target_port_id' => 'Port yang dipilih tidak ditemukan.']);
            }

            foreach (['port' => $source, 'target_port_id' => $target] as $field => $candidate) {
                if ($candidate->linked_port_id !== null) {
                    throw ValidationException::withMessages([$field => self::linkedMessage($candidate)]);
                }
            }

            AssetPort::query()->whereKey($source->id)->update(['linked_port_id' => $target->id]);
            AssetPort::query()->whereKey($target->id)->update(['linked_port_id' => $source->id]);

            AuditService::log('network_asset', 'port_linked', [
                'port_id' => $source->id,
                'target_port_id' => $target->id,
                'asset_id' => $source->network_asset_id,
                'target_asset_id' => $target->network_asset_id,
            ], $source);
        }, 3);
    }

    /** Clears the link of the port and of the port it points at. */
    public static function unlink(AssetPort $port): void
    {
        DB::transaction(function () use ($port): void {
            // An unlocked peek names the partner, so both rows are then locked together, in id order.
            $partnerId = AssetPort::query()->whereKey($port->id)->value('linked_port_id');

            if ($partnerId === null) {
                throw ValidationException::withMessages(['port' => 'Port ini tidak terhubung.']);
            }

            $ports = self::lockPorts([$port->id, $partnerId]);
            $source = $ports[$port->id] ?? throw ValidationException::withMessages(['port' => 'Port tidak ditemukan.']);

            if ($source->linked_port_id !== $partnerId) {
                throw ValidationException::withMessages(['port' => 'Sambungan port ini baru saja berubah. Muat ulang halaman.']);
            }

            $partner = $ports[$partnerId] ?? null;
            $cleared = $partner?->linked_port_id === $source->id ? [$source->id, $partner->id] : [$source->id];

            AssetPort::query()->whereIn('id', $cleared)->update(['linked_port_id' => null]);

            AuditService::log('network_asset', 'port_unlinked', [
                'port_id' => $source->id,
                'target_port_id' => $partnerId,
                'asset_id' => $source->network_asset_id,
            ], $source);
        }, 3);
    }

    /**
     * Mounts the asset in a rack at unit $unit with a height of $height units, or takes it out ($rackId null). The rack
     * row is locked before the asset's, so two placements into one rack cannot both take a unit.
     */
    public static function place(NetworkAsset $asset, ?int $rackId, ?int $unit, ?int $height): void
    {
        DB::transaction(function () use ($asset, $rackId, $unit, $height): void {
            $rack = $rackId === null ? null : self::lockRack($rackId);
            $device = NetworkAsset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($rack === null) {
                self::unmount($device);

                return;
            }

            if ($device->asset_type === 'rack') {
                throw ValidationException::withMessages(['rack_asset_id' => 'Rak tidak dapat dipasang di dalam rak.']);
            }

            if ($rack->height_u === null) {
                throw ValidationException::withMessages(['rack_asset_id' => 'Rak belum punya ukuran.']);
            }

            if ($unit === null || $height === null || $unit < 1 || $height < 1) {
                throw ValidationException::withMessages(['rack_unit' => 'Isi posisi dan tinggi perangkat, mulai dari 1U.']);
            }

            $top = $unit + $height - 1;

            if ($top > $rack->height_u) {
                throw ValidationException::withMessages(['rack_unit' => "Posisi melewati tinggi rak ({$rack->height_u}U)."]);
            }

            $occupant = self::mounted($rack)
                ->whereKeyNot($device->id)
                ->where('rack_unit', '<=', $top)
                ->whereRaw('rack_unit + COALESCE(height_u, 1) - 1 >= ?', [$unit])
                ->orderBy('rack_unit')
                ->first(['code', 'rack_unit', 'height_u']);

            if ($occupant !== null) {
                $low = $occupant->rack_unit;
                $high = $low + ($occupant->height_u ?? 1) - 1;

                throw ValidationException::withMessages(['rack_unit' => ($low === $high ? "U{$low}" : "U{$low}–U{$high}")." sudah terisi {$occupant->code}."]);
            }

            $device->forceFill(['rack_asset_id' => $rack->id, 'rack_unit' => $unit, 'height_u' => $height]);

            if ($device->isDirty()) {
                $device->save();

                AuditService::log('network_asset', 'rack_placed', ['asset_id' => $device->id, 'rack_id' => $rack->id, 'unit' => $unit, 'height_u' => $height], $device);
            }
        }, 3);
    }

    /**
     * A linked port, or a rack that still holds devices, keeps its asset from a delete. The lifecycle references
     * (NetworkAsset::lifecycleReferences) refuse any port row and any mounted device with a general message; call this
     * first for the specific one.
     */
    public static function assertDeletable(NetworkAsset $asset): void
    {
        if (AssetPort::query()->where('network_asset_id', $asset->id)->whereNotNull('linked_port_id')->exists()) {
            throw ValidationException::withMessages(['lifecycle' => 'Port aset ini masih terhubung ke port lain. Lepas sambungannya dulu.']);
        }

        if (self::mounted($asset)->exists()) {
            throw ValidationException::withMessages(['lifecycle' => 'Rak ini masih berisi perangkat. Kosongkan rak dulu.']);
        }
    }

    private static function exclusive(NetworkAsset $asset, Closure $callback): void
    {
        try {
            Cache::lock("asset-panel:{$asset->company_id}:{$asset->id}", 30)->block(5, $callback);
        } catch (LockTimeoutException) {
            abort(409, 'Panel perangkat ini sedang diubah. Coba lagi.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{0: ?PortTemplate, 1: array<string, mixed>} the catalogue row (null for a custom layout) and the layout to store
     */
    private static function resolve(NetworkAsset $asset, ?string $templateKey, ?array $layout): array
    {
        if ($templateKey !== null && $layout !== null) {
            throw ValidationException::withMessages(['layout' => 'Pilih model dari katalog atau susun tata letak sendiri, bukan keduanya.']);
        }

        if ($templateKey !== null) {
            $template = PortTemplate::query()->where('key', $templateKey)->where('asset_type', $asset->asset_type)->first()
                ?? throw ValidationException::withMessages(['template_key' => 'Model perangkat itu tidak ada untuk tipe aset ini.']);

            return [$template, $template->layout];
        }

        if ($layout === null) {
            throw ValidationException::withMessages(['layout' => 'Pilih model perangkat atau susun tata letak.']);
        }

        return [null, PanelLayout::validate($layout, $asset->asset_type)];
    }

    /**
     * What the register behind the layout needs: the PON ports of an OLT, the first splitter of an empty ODP. Both
     * only ever add, so a refusal later in apply() leaves nothing that the model does not call for.
     *
     * @param  array<string, mixed>  $layout
     */
    private static function registerOdn(NetworkAsset $asset, array $layout, string $field): void
    {
        if ($asset->asset_type === 'olt') {
            $slots = count(PanelLayout::ponSlots($layout));
            $highest = (int) OdnPort::query()->where('network_asset_id', $asset->id)->where('kind', OdnPort::KIND_PON)->max('number');

            if ($highest > $slots) {
                throw ValidationException::withMessages([$field => "OLT ini sudah punya {$highest} port PON terdaftar; model ini hanya {$slots}."]);
            }

            for ($missing = $slots - $highest; $missing > 0; $missing -= self::PON_CHUNK) {
                OdnService::createPonPorts($asset, min($missing, self::PON_CHUNK));
            }
        }

        if ($asset->asset_type === 'odp' && ! OdnSplitter::query()->where('network_asset_id', $asset->id)->exists()) {
            foreach (PanelLayout::splitterBlocks($layout) as $block) {
                OdnService::createSplitter($asset, $block['count']);
            }
        }
    }

    /** @param array<string, mixed> $layout */
    private static function reconcile(NetworkAsset $asset, ?PortTemplate $template, array $layout, ?int $heightU): void
    {
        DB::transaction(function () use ($asset, $template, $layout, $heightU): void {
            $locked = NetworkAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $existing = AssetPort::query()->where('network_asset_id', $locked->id)->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (AssetPort $port): string => self::slot($port->group_key, $port->number));
            $wanted = collect(PanelLayout::assetPortRows($layout))
                ->keyBy(fn (array $row): string => self::slot($row['group_key'], $row['number']));

            $stale = $existing->diffKeys($wanted);
            $linked = $stale->filter(fn (AssetPort $port): bool => $port->linked_port_id !== null);

            if ($linked->isNotEmpty()) {
                throw ValidationException::withMessages(['ports' => 'Port '.Arr::join($linked->pluck('name')->all(), ', ', ' dan ').' masih terhubung. Lepas sambungannya dulu.']);
            }

            $added = $wanted->diffKeys($existing);
            $renamed = $wanted->intersectByKeys($existing)
                ->filter(fn (array $row, string $slot): bool => $existing[$slot]->kind !== $row['kind'] || $existing[$slot]->name !== $row['name']);
            $now = now();

            AssetPort::query()->insert($added->map(fn (array $row): array => [
                ...$row,
                'public_id' => PublicId::new(),
                'company_id' => $locked->company_id,
                'network_asset_id' => $locked->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());

            foreach ($renamed as $slot => $row) {
                AssetPort::query()->whereKey($existing[$slot]->id)->update(['kind' => $row['kind'], 'name' => $row['name']]);
            }

            AssetPort::query()->whereIn('id', $stale->pluck('id')->all())->delete();

            // A mounted device keeps the height it was placed with: a new model must not push it into its neighbour.
            $height = $locked->rack_asset_id !== null ? $locked->height_u : ($template->height_u ?? $heightU ?? $locked->height_u);
            // Loose comparison on purpose: MySQL hands the stored JSON back with its keys in its own order.
            $snapshotChanged = $locked->template_key !== $template?->key || $locked->port_layout != $layout || $locked->height_u !== $height;

            if ($snapshotChanged) {
                $locked->forceFill(['template_key' => $template?->key, 'port_layout' => $layout, 'height_u' => $height])->save();
            }

            if ($snapshotChanged || $added->isNotEmpty() || $renamed->isNotEmpty() || $stale->isNotEmpty()) {
                AuditService::log('network_asset', 'panel_applied', [
                    'asset_id' => $locked->id,
                    'template_key' => $template?->key,
                    'ports_added' => $added->count(),
                    'ports_renamed' => $renamed->count(),
                    'ports_removed' => $stale->count(),
                ], $locked);
            }
        }, 3);
    }

    /** @param array<string, mixed>|null $layout */
    private static function resizeRack(NetworkAsset $asset, ?string $templateKey, ?array $layout, ?int $heightU): void
    {
        if ($templateKey !== null || $layout !== null) {
            throw ValidationException::withMessages(['layout' => 'Rak hanya punya ukuran; model dan tata letak tidak berlaku.']);
        }

        if ($heightU === null) {
            throw ValidationException::withMessages(['height_u' => 'Isi ukuran rak dalam U.']);
        }

        DB::transaction(function () use ($asset, $heightU): void {
            $rack = NetworkAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $top = (int) self::mounted($rack)->max(DB::raw('rack_unit + COALESCE(height_u, 1) - 1'));

            if ($heightU < $top) {
                throw ValidationException::withMessages(['height_u' => "Rak berisi perangkat sampai U{$top}; ukurannya tidak boleh lebih kecil."]);
            }

            if ($rack->height_u === $heightU) {
                return;
            }

            $rack->forceFill(['height_u' => $heightU])->save();

            AuditService::log('network_asset', 'panel_applied', ['asset_id' => $rack->id, 'height_u' => $heightU], $rack);
        }, 3);
    }

    private static function unmount(NetworkAsset $device): void
    {
        if ($device->rack_asset_id === null) {
            return;
        }

        $rackId = $device->rack_asset_id;
        $unit = $device->rack_unit;

        $device->forceFill(['rack_asset_id' => null, 'rack_unit' => null])->save();

        AuditService::log('network_asset', 'rack_unplaced', ['asset_id' => $device->id, 'rack_id' => $rackId, 'unit' => $unit], $device);
    }

    private static function lockRack(int $rackId): NetworkAsset
    {
        return NetworkAsset::query()->where('asset_type', 'rack')->lockForUpdate()->find($rackId)
            ?? throw ValidationException::withMessages(['rack_asset_id' => 'Rak tidak ditemukan.']);
    }

    /**
     * The assets mounted in a rack. Deleted ones count: they still hold their unit, and the foreign key keeps the rack.
     *
     * @return Builder<NetworkAsset>
     */
    private static function mounted(NetworkAsset $rack): Builder
    {
        return NetworkAsset::query()->withTrashed()->where('rack_asset_id', $rack->id);
    }

    /**
     * Locked in id order, so two writers that share ports never wait for each other in opposite order. Another
     * company's id is simply not found.
     *
     * @param  list<int>  $ids
     * @return Collection<int, AssetPort> keyed by id
     */
    private static function lockPorts(array $ids): Collection
    {
        return AssetPort::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private static function linkedMessage(AssetPort $port): string
    {
        $code = NetworkAsset::query()->withTrashed()->whereKey($port->network_asset_id)->value('code');

        return "Port {$port->name} di {$code} sudah terhubung.";
    }

    private static function slot(string $group, int $number): string
    {
        return "{$group}:{$number}";
    }
}
