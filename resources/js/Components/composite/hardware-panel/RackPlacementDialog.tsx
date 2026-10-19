import type { FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { RemoteSearchSelect } from '@/Components/composite/RemoteSearchSelect';
import { Alert, AlertDescription, Button, Input, Modal } from '@/Components/ui';
import type { PageProps } from '@/types';
import type { AssetPanelPageProps, PanelPayload, RackItem } from '@/types/hardware-panel';

export interface RackPlacementDialogProps {
    rack: PanelPayload['asset'];
    heightU: number;
    mode: { kind: 'place'; unit: number } | { kind: 'edit'; item: RackItem };
    onClose: () => void;
}

/** Highest device the server mounts (UpdateRackPlacementRequest). */
const MAX_DEVICE_U = 10;

/**
 * Mounts a device in the rack at the chosen U, moves or resizes one that is mounted, or takes it
 * out. The route belongs to the device, so the device is the `{asset}` and the rack goes in the
 * body. Mount it only while open, so each opening starts clean.
 */
export function RackPlacementDialog({ rack, heightU, mode, onClose }: RackPlacementDialogProps) {
    const { rackAssetOptions } = usePage<PageProps>().props as PageProps & AssetPanelPageProps;
    const item = mode.kind === 'edit' ? mode.item : null;
    const unit = mode.kind === 'place' ? mode.unit : mode.item.unit;
    const title = item ? `${item.code} di ${rack.code}` : `Pasang perangkat di U${unit}`;
    const form = useForm({
        device: item?.id ?? '',
        rack_unit: String(unit),
        height_u: String(item?.height ?? 1),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const save = (body: object) => {
        form.transform(() => body);
        form.put(route('admin.network-assets.rack-placement.update', form.data.device), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };
    const place = (event: FormEvent) => {
        event.preventDefault();
        save({
            rack_asset_id: rack.id,
            rack_unit: Number(form.data.rack_unit),
            height_u: Number(form.data.height_u),
        });
    };

    return (
        <Modal open onClose={onClose} title={title}>
            <form onSubmit={place} className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    {rack.code}, {heightU}U. U1 ada di bagian bawah rak.
                </p>
                {item ? (
                    <div className="space-y-1 text-sm">
                        <p className="wrap-break-word text-foreground">
                            <span className="font-medium">{item.code}</span> · {item.name}
                        </p>
                        <Link
                            href={route('admin.network-assets.show', item.id)}
                            className="font-medium text-primary-text hover:underline"
                        >
                            Buka halaman aset
                        </Link>
                        {errors.rack_asset_id ? (
                            <Alert variant="danger" className="mt-3">
                                <AlertDescription>{errors.rack_asset_id}</AlertDescription>
                            </Alert>
                        ) : null}
                    </div>
                ) : (
                    <RemoteSearchSelect
                        field="rackAsset"
                        id="rack-device"
                        label="Perangkat"
                        value={form.data.device}
                        onChange={(value) => form.setData('device', value)}
                        options={rackAssetOptions}
                        placeholder="Cari kode atau nama perangkat"
                        emptyText="Tidak ada perangkat yang cocok."
                        error={errors.rack_asset_id}
                        required
                    />
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        id="rack-unit"
                        label="Mulai di U"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={heightU}
                        step={1}
                        value={form.data.rack_unit}
                        onChange={(event) => form.setData('rack_unit', event.target.value)}
                        error={errors.rack_unit}
                        required
                    />
                    <Input
                        id="rack-height"
                        label="Tinggi (U)"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={MAX_DEVICE_U}
                        step={1}
                        value={form.data.height_u}
                        onChange={(event) => form.setData('height_u', event.target.value)}
                        error={errors.height_u}
                        required
                    />
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    {item ? (
                        <Button
                            type="button"
                            variant="destructive-outline"
                            className="mr-auto"
                            disabled={form.processing}
                            onClick={() => save({ rack_asset_id: null })}
                        >
                            Lepas dari rak
                        </Button>
                    ) : null}
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.device === ''}
                    >
                        {item ? 'Simpan posisi' : 'Pasang di rak'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
