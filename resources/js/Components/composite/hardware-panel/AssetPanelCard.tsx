import { lazy, Suspense, useCallback, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Modal } from '@/Components/ui';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { PANEL_ASSET_TYPES } from '@/types/hardware-panel';
import type { AssetPanelPageProps, PanelPayload, PortDetail } from '@/types/hardware-panel';
import type { NetworkAssetType } from '@/types/network-asset';
import type { PortSelection } from './HardwarePanel';
import { HardwarePanelLazy, PanelSkeleton, RackPanelLazy } from './HardwarePanelLazy';
import { PanelTemplatePicker } from './PanelTemplatePicker';
import { RackPlacementDialog } from './RackPlacementDialog';
import type { RackPlacementDialogProps } from './RackPlacementDialog';

// The sheet is the biggest part after the drawing, and only a click needs it.
const PortSheet = lazy(() =>
    import('./PortSheet').then((module) => ({ default: module.PortSheet })),
);

export interface AssetPanelCardProps {
    assetId: string;
    assetType: NetworkAssetType;
    /** Default "Panel perangkat". */
    title?: string;
}

const MODEL_LOCKED_TEXT = 'Mengubah model OLT/ODP perlu izin kelola ODN.';

/**
 * The hardware panel of an asset on a page that shows it: the drawing, a picker while the unit has no
 * model, or the rack with its units. It reads the page props the controller added
 * (`AssetPanelProps::for`) and renders only for the types that have a panel.
 */
export function AssetPanelCard(props: AssetPanelCardProps) {
    const can = usePermission();

    // The server sends no panel props to a user who may not view the asset; do not wait for them.
    if (
        !can('network_asset.view') ||
        !(PANEL_ASSET_TYPES as readonly string[]).includes(props.assetType)
    ) {
        return null;
    }

    return <PanelCard {...props} />;
}

/** What the header says under the title: the model, or the size of the rack. */
function describe(panel: PanelPayload): string | null {
    if (panel.rack) {
        return panel.rack.height_u === null
            ? null
            : `${panel.rack.height_u}U · ${panel.rack.items.length} perangkat`;
    }

    const { template } = panel;
    if (template === null || panel.asset.type === 'odc' || panel.asset.type === 'odp') return null;
    if (template.vendor !== null && template.model !== null) {
        return `${template.vendor} ${template.model}${template.height_u === null ? '' : ` · ${template.height_u}U`}`;
    }

    const ports = panel.groups.reduce((sum, group) => sum + group.ports.length, 0);

    return `Susunan manual · ${ports} port`;
}

/** The selection with the port as the latest panel has it, so the sheet shows a state a write just changed. */
function refresh(selection: PortSelection, panel: PanelPayload | undefined): PortSelection {
    const group = panel?.groups.find((candidate) => candidate.key === selection.group.key);
    const port = group?.ports.find((candidate) => candidate.number === selection.port.number);

    return group && port ? { ...selection, group, port } : selection;
}

function PanelCard({ assetId, assetType, title = 'Panel perangkat' }: AssetPanelCardProps) {
    const page = usePage<PageProps>();
    const props = page.props as PageProps & AssetPanelPageProps;
    const can = usePermission();
    const canUpdate = can('network_asset.update');

    // `panel` is deferred: it is undefined at first and again while a write makes the page fetch it anew.
    // The last one stays on screen meanwhile, so the drawing does not flash back to a skeleton.
    const [panel, setPanel] = useState(props.panel);
    if (props.panel !== undefined && props.panel !== panel) setPanel(props.panel);

    const [selection, setSelection] = useState<PortSelection | null>(null);
    const [detail, setDetail] = useState<{ id: string; value: PortDetail | null } | null>(null);
    const [changing, setChanging] = useState(false);
    const [resizing, setResizing] = useState(false);

    // The detail of one port is a partial reload; it is kept here, so a write that makes the page
    // replace its props never shows the sheet an empty detail.
    const loadDetail = useCallback((id: string) => {
        router.reload({
            only: ['portDetail'],
            data: { port: id },
            replace: true,
            onSuccess: (loaded) =>
                setDetail({ id, value: (loaded.props as AssetPanelPageProps).portDetail ?? null }),
            // Nothing came back and nothing was shown before: say so instead of loading for ever.
            onFinish: () => setDetail((shown) => (shown?.id === id ? shown : { id, value: null })),
        });
    }, []);
    const openPort = useCallback(
        (next: PortSelection) => {
            setSelection(next);
            if (next.port.id !== null) loadDetail(next.port.id);
        },
        [loadDetail],
    );
    const closePort = useCallback(() => setSelection(null), []);
    const live = useMemo(
        () => (selection === null ? null : refresh(selection, panel)),
        [selection, panel],
    );

    const rack = panel?.rack;
    const modelLocked =
        canUpdate && (assetType === 'olt' || assetType === 'odp') && !can('odn.manage');
    const changeable =
        canUpdate &&
        panel?.template !== undefined &&
        panel.template !== null &&
        ['router', 'switch', 'olt', 'onu_ont'].includes(assetType);
    const resizable = canUpdate && rack !== undefined && rack.height_u !== null;
    const portId = live?.port.id ?? null;
    const onOdnPage = page.component === 'Admin/Odn/Asset';

    // The ODN page has its own empty state for a unit without splitters.
    if (
        onOdnPage &&
        ['odc', 'odp'].includes(assetType) &&
        panel?.template === null &&
        !(canUpdate && panel.picker)
    ) {
        return null;
    }

    let body: ReactNode;
    if (panel === undefined) {
        body = <PanelSkeleton />;
    } else if (panel.rack) {
        body = <RackBody asset={panel.asset} rack={panel.rack} canUpdate={canUpdate} />;
    } else if (panel.template !== null) {
        body = (
            <HardwarePanelLazy
                panel={panel}
                label={`Port ${panel.asset.code}`}
                selectedKey={selection?.key ?? null}
                onSelectPort={openPort}
            />
        );
    } else {
        body = <EmptyBody panel={panel} canUpdate={canUpdate} onOdnPage={onOdnPage} />;
    }

    return (
        <Card>
            <CardHeader className="sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1.5">
                    <CardTitle>{title}</CardTitle>
                    {panel && describe(panel) ? (
                        <p className="text-sm text-muted-foreground">{describe(panel)}</p>
                    ) : null}
                </div>
                {modelLocked && panel?.template ? (
                    <p className="self-start text-sm text-muted-foreground">{MODEL_LOCKED_TEXT}</p>
                ) : changeable && !modelLocked ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() => setChanging(true)}
                    >
                        Ganti model
                    </Button>
                ) : null}
                {resizable ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() => setResizing(true)}
                    >
                        Ubah ukuran
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent>{body}</CardContent>

            {panel && live ? (
                <Suspense fallback={null}>
                    <PortSheet
                        asset={panel.asset}
                        panel={panel}
                        selection={live}
                        detail={
                            portId === null
                                ? null
                                : detail?.id === portId
                                  ? detail.value
                                  : undefined
                        }
                        onClose={closePort}
                        onChanged={() => {
                            if (portId !== null) loadDetail(portId);
                        }}
                    />
                </Suspense>
            ) : null}
            {panel && changing ? (
                <Modal
                    open
                    onClose={() => setChanging(false)}
                    title="Ganti model perangkat"
                    size="lg"
                >
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Port yang bernama sama tetap menyimpan label, catatan, dan sambungannya.
                            Port yang tidak ada di model baru dihapus; port yang masih terhubung
                            menahan penggantian.
                        </p>
                        <PanelTemplatePicker
                            asset={panel.asset}
                            picker={panel.picker ?? null}
                            canUpdate={canUpdate}
                            current={panel.template}
                            onDone={() => setChanging(false)}
                        />
                    </div>
                </Modal>
            ) : null}
            {rack && resizing ? (
                <Modal open onClose={() => setResizing(false)} title="Ubah ukuran rak" size="sm">
                    <RackSizeForm
                        assetId={assetId}
                        current={rack.height_u}
                        onDone={() => setResizing(false)}
                    />
                </Modal>
            ) : null}
        </Card>
    );
}

/** The panel of a unit with no drawing yet: pick or compose a model, or say what is missing. */
function EmptyBody({
    panel,
    canUpdate,
    onOdnPage,
}: {
    panel: PanelPayload;
    canUpdate: boolean;
    onOdnPage: boolean;
}) {
    const can = usePermission();
    const { asset, picker } = panel;
    const modelLocked =
        canUpdate && (asset.type === 'olt' || asset.type === 'odp') && !can('odn.manage');

    if (panel.odn_hidden) {
        return (
            <p className="text-sm text-muted-foreground">
                Port PON dan splitter tampil untuk pengguna dengan akses ODN.
            </p>
        );
    }

    if (asset.type === 'odc' || asset.type === 'odp') {
        return (
            <div className="space-y-4">
                {onOdnPage ? null : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Belum ada splitter. Tambah splitter di halaman ODN.
                        </p>
                        {can('odn.manage') ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('admin.odn.assets.show', asset.id)}>
                                    Buka halaman ODN
                                </Link>
                            </Button>
                        ) : null}
                    </>
                )}
                {modelLocked ? (
                    <p className="text-sm text-muted-foreground">{MODEL_LOCKED_TEXT}</p>
                ) : picker && canUpdate ? (
                    <div className={cn('space-y-4', !onOdnPage && 'border-t pt-4')}>
                        <h3 className="text-base font-semibold text-foreground">
                            {onOdnPage ? 'Pilih model perangkat' : 'Atau pilih model perangkat'}
                        </h3>
                        <PanelTemplatePicker asset={asset} picker={picker} canUpdate={canUpdate} />
                    </div>
                ) : null}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {canUpdate && !modelLocked ? (
                <h3 className="text-base font-semibold text-foreground">Pilih model perangkat</h3>
            ) : null}
            {modelLocked ? (
                <p className="text-sm text-muted-foreground">{MODEL_LOCKED_TEXT}</p>
            ) : (
                <PanelTemplatePicker asset={asset} picker={picker ?? null} canUpdate={canUpdate} />
            )}
        </div>
    );
}

/** A rack with its units: one button per empty U for an editor, and the rack size to set first. */
function RackBody({
    asset,
    rack,
    canUpdate,
}: {
    asset: PanelPayload['asset'];
    rack: NonNullable<PanelPayload['rack']>;
    canUpdate: boolean;
}) {
    const [dialog, setDialog] = useState<RackPlacementDialogProps['mode'] | null>(null);

    if (rack.height_u === null) {
        return canUpdate ? (
            <div className="space-y-4">
                <h3 className="text-base font-semibold text-foreground">Tentukan ukuran rak</h3>
                <RackSizeForm assetId={asset.id} current={null} />
            </div>
        ) : (
            <p className="text-sm text-muted-foreground">
                Ukuran rak belum ditentukan. Minta admin menentukannya agar isi rak tampil.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {rack.items.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Rak masih kosong.{canUpdate ? ' Pilih slot U untuk memasang perangkat.' : ''}
                </p>
            ) : null}
            <RackPanelLazy
                asset={asset}
                heightU={rack.height_u}
                items={rack.items}
                label={`Isi ${asset.code}`}
                onSelectSlot={canUpdate ? (unit) => setDialog({ kind: 'place', unit }) : undefined}
                onSelectItem={(item) =>
                    canUpdate
                        ? setDialog({ kind: 'edit', item })
                        : router.visit(route('admin.network-assets.show', item.id))
                }
            />
            {dialog ? (
                <RackPlacementDialog
                    rack={asset}
                    heightU={rack.height_u}
                    mode={dialog}
                    onClose={() => setDialog(null)}
                />
            ) : null}
        </div>
    );
}

function RackSizeForm({
    assetId,
    current,
    onDone,
}: {
    assetId: string;
    current: number | null;
    onDone?: () => void;
}) {
    const form = useForm({ height_u: String(current ?? 42) });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ height_u: Number(data.height_u) }));
        form.put(route('admin.network-assets.panel.update', assetId), {
            preserveScroll: true,
            onSuccess: () => onDone?.(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div className="sm:w-48">
                    <Input
                        id="rack-size"
                        label="Tinggi rak (U)"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={52}
                        step={1}
                        value={form.data.height_u}
                        onChange={(event) => form.setData('height_u', event.target.value)}
                        error={errors.height_u ?? errors.layout}
                        required
                    />
                </div>
                <Button
                    type="submit"
                    variant="primary"
                    className="sm:mt-6"
                    loading={form.processing}
                    disabled={form.data.height_u.trim() === '' || form.recentlySuccessful}
                >
                    Simpan
                </Button>
            </div>
            {form.recentlySuccessful ? (
                <p role="status" className="text-sm text-muted-foreground">
                    Ukuran disimpan. Rak sedang digambar.
                </p>
            ) : null}
        </form>
    );
}
