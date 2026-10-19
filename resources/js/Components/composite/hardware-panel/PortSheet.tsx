import { Fragment, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ConfirmRequestDialog } from '@/Components/composite/ConfirmRequestDialog';
import type { ConfirmRequest } from '@/Components/composite/ConfirmRequestDialog';
import { BlockedBadge, SubscriptionBlockModal } from '@/Components/composite/NetworkAccessBadges';
import type { BlockTarget } from '@/Components/composite/NetworkAccessBadges';
import { RemoteSearchSelect } from '@/Components/composite/RemoteSearchSelect';
import {
    Alert,
    AlertDescription,
    Button,
    EnumBadge,
    Input,
    SearchSelect,
    Skeleton,
    Switch,
    Textarea,
} from '@/Components/ui';
import type { SearchSelectOption } from '@/Components/ui';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/format';
import { PORT_STATE, SUBSCRIPTION_STATUS } from '@/lib/statuses';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type {
    AssetPanelPageProps,
    PanelPayload,
    PortBlock,
    PortDetail,
    PortStateKey,
} from '@/types/hardware-panel';
import type { PortSelection } from './HardwarePanel';
import { layoutPanel, portStateKey } from './panelGeometry.helpers';

export interface PortSheetProps {
    asset: PanelPayload['asset'];
    /** The panel the port is on: its state colours, model and ODN numbering. */
    panel: PanelPayload;
    selection: PortSelection;
    /** The detail of the port; undefined while it loads, null when the server has none. */
    detail: PortDetail | null | undefined;
    onClose: () => void;
    /** A write went through: the card loads the detail again. */
    onChanged: () => void;
}

const LINK = 'font-medium text-primary-text hover:underline';
const WIDE = '(min-width: 640px)';
const HEADING = 'text-sm font-semibold text-foreground';

const subscribeWide = (notify: () => void) => {
    const query = window.matchMedia(WIDE);
    query.addEventListener('change', notify);

    return () => query.removeEventListener('change', notify);
};

/** Speed of the block the port is in, and its position in the ODN register when it is a PON port. */
function portFacts(panel: PanelPayload, selection: PortSelection) {
    const { template } = panel;
    if (template === null) return { speed: null, ordinal: null };

    const { number } = selection.port;
    const block = template.layout.rows
        .flat()
        .find(
            (candidate): candidate is PortBlock =>
                'group' in candidate &&
                candidate.group === selection.group.key &&
                number >= (candidate.from ?? 1) &&
                number < (candidate.from ?? 1) + candidate.count,
        );
    const ordinal =
        layoutPanel(template.layout, panel.groups, null).ports.find(
            (candidate) => candidate.key === selection.key,
        )?.ordinal ?? null;

    return { speed: block?.speed ?? null, ordinal };
}

/** Focus goes back to the port that opened the sheet; after a refetch its button may be a new element. */
function returnFocus({ key, trigger }: PortSelection) {
    const button = trigger.isConnected
        ? trigger
        : document.querySelector<HTMLElement>(`[data-port-button="${CSS.escape(key)}"]`);
    button?.focus();
}

/**
 * The side sheet of one port: what it is and what is behind it, and the actions that exist for it,
 * each asking only the permission it needs. From the bottom on a phone, from the right above 640px.
 * Refusals of the server stay in the sheet, next to the field or button they are about.
 */
export function PortSheet({ asset, panel, selection, detail, onClose, onChanged }: PortSheetProps) {
    const wide = useSyncExternalStore(
        subscribeWide,
        () => window.matchMedia(WIDE).matches,
        () => true,
    );
    const { group, port } = selection;
    const state = portStateKey(port, panel.online);
    const facts = useMemo(() => portFacts(panel, selection), [panel, selection]);

    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <SheetContent
                side={wide ? 'right' : 'bottom'}
                className={cn(
                    'overflow-y-auto motion-reduce:transition-none',
                    wide ? 'w-full sm:max-w-md' : 'max-h-[85dvh]',
                )}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnFocus(selection);
                }}
            >
                <SheetHeader className="pr-8">
                    <SheetTitle className="wrap-break-word">
                        {port.label ? `${port.name} · ${port.label}` : port.name}
                    </SheetTitle>
                    <SheetDescription>
                        {group.label} · {asset.code}
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-5 mt-5">
                    <Summary
                        selection={selection}
                        panel={panel}
                        detail={detail}
                        state={state}
                        speed={facts.speed}
                        ordinal={facts.ordinal}
                    />
                    {port.id === null ? (
                        <StaticActions asset={asset} selection={selection} />
                    ) : detail === undefined ? (
                        <div role="status" aria-label="Memuat detail port" className="space-y-3">
                            <Skeleton className="h-5 w-2/3" />
                            <Skeleton className="h-20 w-full" />
                        </div>
                    ) : detail === null ? (
                        <Alert variant="warning">
                            <AlertDescription>
                                Detail port tidak dapat dimuat. Tutup panel ini lalu buka lagi, atau
                                muat ulang halaman.
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <PortActions
                            asset={asset}
                            selection={selection}
                            detail={detail}
                            onChanged={onChanged}
                        />
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function Summary({
    selection,
    panel,
    detail,
    state,
    speed,
    ordinal,
}: {
    selection: PortSelection;
    panel: PanelPayload;
    detail: PortDetail | null | undefined;
    state: PortStateKey;
    speed: string | null;
    ordinal: number | null;
}) {
    const can = usePermission();
    const { port, group } = selection;
    const behind = detail?.behind ?? port.behind;

    let connected: ReactNode = null;
    if (detail?.link) {
        connected = (
            <Link href={route('admin.network-assets.show', detail.link.asset.id)} className={LINK}>
                {detail.link.asset.code} · {detail.link.port.name}
            </Link>
        );
    } else if (detail?.feeds && can('odn.view')) {
        connected = (
            <Link href={route('admin.odn.assets.show', detail.feeds.asset.id)} className={LINK}>
                {detail.feeds.asset.code} · {detail.feeds.splitter}
            </Link>
        );
    } else if (port.link) {
        connected = `${port.link.asset} · ${port.link.port}`;
    } else if (group.role === 'splitter_in') {
        connected = 'Belum terhubung';
    }

    const rows: [string, ReactNode][] = [
        ['Status', <EnumBadge key="state" map={PORT_STATE} value={state} />],
    ];
    if (speed !== null) rows.push(['Kecepatan', speed]);
    if (ordinal !== null) rows.push(['Di register ODN', `PON ${ordinal}`]);
    if (connected !== null) rows.push(['Terhubung ke', connected]);
    if (behind) {
        rows.push([
            'Pelanggan di bawah',
            `${behind.total} pelanggan${panel.online.known ? ` · ${behind.online} online` : ''}`,
        ]);
    }
    if (detail?.note) rows.push(['Catatan', detail.note]);

    return (
        <dl className="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
            {rows.map(([label, value]) => (
                <Fragment key={label}>
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="wrap-break-word text-foreground">{value}</dd>
                </Fragment>
            ))}
        </dl>
    );
}

/** A port with no register row: a model slot not yet registered, or the input of a splitter. Nothing is requested for it. */
function StaticActions({
    asset,
    selection,
}: {
    asset: PanelPayload['asset'];
    selection: PortSelection;
}) {
    const can = usePermission();
    const onOdnPage = usePage().component === 'Admin/Odn/Asset';
    const isInput = selection.group.role === 'splitter_in';

    return (
        <div className="space-y-3 text-sm">
            <p className="text-muted-foreground">
                {isInput
                    ? `Masukan splitter ini ${selection.port.link ? 'terhubung ke' : 'belum terhubung ke'} port hulu. ${
                          onOdnPage
                              ? 'Atur sambungannya lewat kolom Port masukan di kartu splitter.'
                              : 'Atur sambungannya di halaman ODN.'
                      }`
                    : 'Port ini ada di model, tetapi belum terdaftar di ODN.'}
            </p>
            {can('odn.view') && !onOdnPage ? (
                <Button asChild variant="outline" size="sm">
                    <Link href={route('admin.odn.assets.show', asset.id)}>Buka halaman ODN</Link>
                </Button>
            ) : null}
        </div>
    );
}

function PortActions({
    asset,
    selection,
    detail,
    onChanged,
}: {
    asset: PanelPayload['asset'];
    selection: PortSelection;
    detail: PortDetail;
    onChanged: () => void;
}) {
    const can = usePermission();
    const onOdnPage = usePage().component === 'Admin/Odn/Asset';
    const fromOdn = detail.source === 'odn';
    // A port that feeds a splitter opens that splitter's page; any other ODN port opens its own asset's.
    const odnTarget = detail.feeds?.asset.id ?? asset.id;
    const freeOutput =
        fromOdn &&
        asset.type === 'odp' &&
        selection.group.role === 'splitter_out' &&
        detail.drop === null &&
        detail.feeds === null;

    return (
        <div className="space-y-5">
            {detail.drop ? (
                <DropSection
                    drop={detail.drop}
                    odnPortId={fromOdn ? detail.id : null}
                    portName={detail.name}
                    onChanged={onChanged}
                />
            ) : null}
            {freeOutput && can('odn.assign') ? (
                <AssignDropForm portId={detail.id} onChanged={onChanged} />
            ) : null}
            {!fromOdn && can('network_asset.update') ? (
                <>
                    <LinkSection asset={asset} detail={detail} onChanged={onChanged} />
                    <PortEditForm asset={asset} detail={detail} onChanged={onChanged} />
                </>
            ) : null}
            {fromOdn && can('odn.view') && !(onOdnPage && odnTarget === asset.id) ? (
                <Button asChild variant="outline" size="sm">
                    <Link href={route('admin.odn.assets.show', odnTarget)}>Buka halaman ODN</Link>
                </Button>
            ) : null}
        </div>
    );
}

/** The subscriber on a drop: who, how they are doing, and the actions that exist for them. */
function DropSection({
    drop,
    odnPortId,
    portName,
    onChanged,
}: {
    drop: NonNullable<PortDetail['drop']>;
    /** The ODN port the drop is on, so it can be released; null on the ONT, which does not own the drop. */
    odnPortId: string | null;
    portName: string;
    onChanged: () => void;
}) {
    const can = usePermission();
    const [blocking, setBlocking] = useState(false);
    const [releasing, setReleasing] = useState<ConfirmRequest | null>(null);
    const { subscription, customer, session } = drop;

    let sessionText = 'Status online tidak tersedia.';
    if (session) {
        sessionText = session.online
            ? `Online${session.since ? ` sejak ${formatDateTime(session.since)}` : ''}`
            : 'Offline';
    }

    // The modal only asks whether the subscription is blocked, which is whether `blocked_at` is set.
    const target: BlockTarget | null = blocking
        ? {
              id: subscription.id,
              code: subscription.code,
              status: subscription.status,
              blocked_at: subscription.blocked ? 'blocked' : null,
          }
        : null;

    return (
        <section aria-labelledby="port-drop-heading" className="space-y-3">
            <h3 id="port-drop-heading" className={HEADING}>
                Pelanggan
            </h3>
            <dl className="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
                <dt className="text-muted-foreground">Langganan</dt>
                <dd className="flex flex-wrap items-center gap-2 text-foreground">
                    {subscription.code}
                    <EnumBadge map={SUBSCRIPTION_STATUS} value={subscription.status} />
                    {subscription.blocked ? <BlockedBadge /> : null}
                </dd>
                <dt className="text-muted-foreground">Pelanggan</dt>
                <dd className="wrap-break-word text-foreground">{customer.name}</dd>
                {drop.package ? (
                    <>
                        <dt className="text-muted-foreground">Paket</dt>
                        <dd className="wrap-break-word text-foreground">{drop.package}</dd>
                    </>
                ) : null}
                <dt className="text-muted-foreground">Sesi</dt>
                <dd className="text-foreground">{sessionText}</dd>
            </dl>
            <div className="flex flex-wrap gap-2">
                {can('customer.subscription.view') ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={route('admin.subscriptions.show', subscription.id)}>
                            Buka langganan
                        </Link>
                    </Button>
                ) : null}
                {can('customer.view') ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={route('admin.customers.show', customer.id)}>
                            Buka pelanggan
                        </Link>
                    </Button>
                ) : null}
                <Button asChild variant="outline" size="sm">
                    <Link
                        href={route('admin.network-assets.trace', {
                            path_subscription: subscription.id,
                        })}
                    >
                        Lacak jalur
                    </Link>
                </Button>
                {can('customer.subscription.block') && subscription.status !== 'terminated' ? (
                    <Button
                        type="button"
                        variant={subscription.blocked ? 'outline' : 'destructive-outline'}
                        size="sm"
                        onClick={() => setBlocking(true)}
                    >
                        {subscription.blocked ? 'Buka blokir' : 'Blokir internet'}
                    </Button>
                ) : null}
                {odnPortId !== null && can('odn.assign') ? (
                    <Button
                        type="button"
                        variant="destructive-outline"
                        size="sm"
                        onClick={() =>
                            setReleasing({
                                title: `Lepas ${subscription.code} dari port ${portName}?`,
                                description:
                                    'Port menjadi kosong. Langganannya sendiri tidak berubah.',
                                confirmLabel: 'Lepas port drop',
                                href: route('admin.odn.drops.destroy', odnPortId),
                            })
                        }
                    >
                        Lepas port drop
                    </Button>
                ) : null}
            </div>
            <SubscriptionBlockModal
                target={target}
                onClose={() => {
                    setBlocking(false);
                    onChanged();
                }}
            />
            {releasing ? (
                <ConfirmRequestDialog
                    key={releasing.href}
                    request={releasing}
                    onClose={() => {
                        setReleasing(null);
                        onChanged();
                    }}
                />
            ) : null}
        </section>
    );
}

/** A free output of an ODP: pick one of the subscriptions it can serve and set its drop here. */
function AssignDropForm({ portId, onChanged }: { portId: string; onChanged: () => void }) {
    const { dropSubscriptionOptions } = usePage<PageProps>().props as PageProps &
        AssetPanelPageProps;
    const form = useForm({ subscription_id: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('admin.odn.drops.store', portId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onChanged();
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <h3 className={HEADING}>Pasang pelanggan</h3>
            <RemoteSearchSelect
                field="dropSubscription"
                id="port-drop-subscription"
                label="Langganan"
                value={form.data.subscription_id}
                onChange={(value) => form.setData('subscription_id', value)}
                options={dropSubscriptionOptions}
                placeholder="Cari kode langganan atau nama pelanggan"
                emptyText="Tidak ada langganan yang bisa dipasang di ODP ini."
                hint="Hanya langganan yang belum punya port drop dan dilayani POP di atas ODP ini."
                error={errors.port ?? errors.subscription_id}
                required
            />
            <Button
                type="submit"
                variant="primary"
                loading={form.processing}
                disabled={form.data.subscription_id === ''}
            >
                Pasang pelanggan
            </Button>
        </form>
    );
}

const toOption = (option: { value: string; label: string; description?: string | null }) => ({
    value: option.value,
    label: option.label,
    description: option.description ?? undefined,
});

/** Patching two ports: the other device is searched on the server, then one of its free ports is picked. */
function LinkSection({
    asset,
    detail,
    onChanged,
}: {
    asset: PanelPayload['asset'];
    detail: PortDetail;
    onChanged: () => void;
}) {
    const { linkAssetOptions } = usePage<PageProps>().props as PageProps & AssetPanelPageProps;
    const [open, setOpen] = useState(false);
    const [targetAsset, setTargetAsset] = useState('');
    const [ports, setPorts] = useState<SearchSelectOption[]>([]);
    const [loadingPorts, setLoadingPorts] = useState(false);
    const [unlinking, setUnlinking] = useState<ConfirmRequest | null>(null);
    const form = useForm({ target_port_id: '' });
    const errors = form.errors as Record<string, string | undefined>;
    // A newer choice of device owns the loading state and cancels the request of the older one. The free
    // ports are kept here: a refused write makes the page replace its props, and the optional prop is gone.
    const latest = useRef(0);
    const cancel = useRef<(() => void) | null>(null);

    const pickAsset = (value: string) => {
        cancel.current?.();
        latest.current += 1;
        const request = latest.current;
        setTargetAsset(value);
        setPorts([]);
        setLoadingPorts(value !== '');
        form.setData('target_port_id', '');
        form.clearErrors();
        if (value === '') return;

        router.reload({
            only: ['linkPortOptions'],
            data: { link_asset: value },
            replace: true,
            onCancelToken: (token) => {
                cancel.current = token.cancel;
            },
            onSuccess: (loaded) => {
                if (latest.current === request) {
                    setPorts(
                        ((loaded.props as AssetPanelPageProps).linkPortOptions ?? []).map(toOption),
                    );
                }
            },
            onFinish: () => {
                if (latest.current === request) setLoadingPorts(false);
            },
        });
    };

    const link = () =>
        form.post(
            route('admin.network-assets.ports.link.store', { asset: asset.id, port: detail.id }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setTargetAsset('');
                    form.reset();
                    onChanged();
                },
            },
        );

    return (
        <section aria-labelledby="port-link-heading" className="space-y-3">
            <h3 id="port-link-heading" className={HEADING}>
                Sambungan
            </h3>
            {detail.link ? (
                <>
                    <Button
                        type="button"
                        variant="destructive-outline"
                        size="sm"
                        onClick={() => {
                            const peer = detail.link;
                            if (peer === null) return;
                            setUnlinking({
                                title: `Lepas sambungan ${detail.name}?`,
                                description: `Port ${detail.name} dan port ${peer.port.name} di ${peer.asset.code} menjadi kosong.`,
                                confirmLabel: 'Lepas sambungan',
                                href: route('admin.network-assets.ports.link.destroy', {
                                    asset: asset.id,
                                    port: detail.id,
                                }),
                            });
                        }}
                    >
                        Lepas sambungan
                    </Button>
                    {errors.port ? (
                        <p className="text-sm text-destructive-text">{errors.port}</p>
                    ) : null}
                </>
            ) : (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-expanded={open}
                        onClick={() => setOpen((shown) => !shown)}
                    >
                        Hubungkan ke port lain
                    </Button>
                    {open ? (
                        <div className="space-y-3">
                            <RemoteSearchSelect
                                field="linkAsset"
                                id="port-link-asset"
                                label="Perangkat tujuan"
                                value={targetAsset}
                                onChange={pickAsset}
                                options={linkAssetOptions}
                                placeholder="Cari kode atau nama perangkat"
                                emptyText="Tidak ada perangkat berpanel yang cocok."
                            />
                            <SearchSelect
                                id="port-link-port"
                                label="Port tujuan"
                                value={form.data.target_port_id}
                                onChange={(value) => form.setData('target_port_id', value)}
                                options={ports}
                                loading={loadingPorts}
                                disabled={targetAsset === ''}
                                placeholder={
                                    targetAsset === ''
                                        ? 'Pilih perangkat tujuan dulu'
                                        : 'Pilih port kosong'
                                }
                                emptyText="Perangkat itu tidak punya port kosong."
                                error={errors.target_port_id ?? errors.port}
                            />
                            <Button
                                type="button"
                                variant="primary"
                                loading={form.processing}
                                disabled={form.data.target_port_id === ''}
                                onClick={link}
                            >
                                Hubungkan
                            </Button>
                        </div>
                    ) : null}
                </>
            )}
            {unlinking ? (
                <ConfirmRequestDialog
                    key={unlinking.href}
                    request={unlinking}
                    onClose={() => {
                        setUnlinking(null);
                        onChanged();
                    }}
                />
            ) : null}
        </section>
    );
}

/** Label, note and the admin-down mark of a port; the mark only shows on the panel, the unit itself is not touched. */
function PortEditForm({
    asset,
    detail,
    onChanged,
}: {
    asset: PanelPayload['asset'];
    detail: PortDetail;
    onChanged: () => void;
}) {
    const form = useForm({
        label: detail.label ?? '',
        note: detail.note ?? '',
        admin_down: detail.admin_down,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(
            route('admin.network-assets.ports.update', { asset: asset.id, port: detail.id }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    // What was saved is the new baseline, so the button waits for the next change.
                    form.setDefaults();
                    onChanged();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <h3 className={HEADING}>Label dan catatan</h3>
            <Input
                id="port-label"
                label="Label"
                value={form.data.label}
                maxLength={64}
                placeholder="Misalnya: ke CCR-INTI"
                onChange={(event) => form.setData('label', event.target.value)}
                error={form.errors.label}
            />
            <Textarea
                id="port-note"
                label="Catatan"
                value={form.data.note}
                maxLength={255}
                rows={2}
                hint="Misalnya VLAN 100 · ke ODC barat."
                onChange={(event) => form.setData('note', event.target.value)}
                error={form.errors.note}
            />
            <div className="space-y-1">
                <Switch
                    checked={form.data.admin_down}
                    onCheckedChange={(checked) => form.setData('admin_down', checked)}
                    label="Nonaktifkan port"
                />
                <p className="text-sm text-muted-foreground">
                    Hanya penanda di panel. Pengaturan perangkatnya tidak berubah.
                </p>
                {form.errors.admin_down ? (
                    <p className="text-sm text-destructive-text">{form.errors.admin_down}</p>
                ) : null}
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    disabled={!form.isDirty}
                >
                    Simpan port
                </Button>
                {form.recentlySuccessful ? (
                    <span role="status" className="text-sm text-muted-foreground">
                        Tersimpan.
                    </span>
                ) : null}
            </div>
        </form>
    );
}
