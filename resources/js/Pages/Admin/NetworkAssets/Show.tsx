import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
    Input,
    Modal,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect, type RemoteOption } from '@/Components/composite';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/format';
import {
    NETWORK_ASSET_OWNERSHIP,
    NETWORK_ASSET_STATUS,
    NETWORK_ASSET_TYPE,
    labelOf,
} from '@/lib/statuses';
import type {
    NetworkAssetOwnership,
    NetworkAssetStatus,
    NetworkAssetType,
} from '@/types/network-asset';

interface InstallRow {
    id: string;
    installed_at: string;
    removed_at?: string | null;
    removal_reason?: string | null;
    location?: { name: string; path?: string } | null;
}

interface AssetData {
    id: string;
    product_id?: string | null;
    code: string;
    name: string;
    asset_type: NetworkAssetType;
    serial_number?: string | null;
    mac_address?: string | null;
    ip_address?: string | null;
    management_ip?: string | null;
    status: NetworkAssetStatus;
    ownership: NetworkAssetOwnership;
    vendor?: string | null;
    model?: string | null;
    notes?: string | null;
    installed_at?: string | null;
    retired_at?: string | null;
    location?: { name: string; path?: string } | null;
    product?: { sku: string; name: string } | null;
    customer?: { id: string; name: string; code: string } | null;
    subscription?: { id: string; code: string } | null;
    installations?: InstallRow[];
}

interface ShowProps extends Record<string, unknown> {
    asset: { data: AssetData };
    /** Sent on a search only (`location_search`), as `SearchOptions` makes it. */
    locationOptions?: RemoteOption[];
}

type LifecycleAction =
    'install' | 'remove' | 'maintenance' | 'resume' | 'damage' | 'repair' | 'retire';

/** What each step is called, whether it asks why, and the status the asset ends up in (the server decides; this only says it). */
const LIFECYCLE: Record<
    LifecycleAction,
    { title: string; confirm: string; reason: boolean; becomes: NetworkAssetStatus }
> = {
    install: { title: 'Pasang aset', confirm: 'Pasang', reason: false, becomes: 'installed' },
    remove: { title: 'Lepas aset', confirm: 'Lepas', reason: true, becomes: 'available' },
    maintenance: {
        title: 'Masukkan ke perawatan',
        confirm: 'Mulai perawatan',
        reason: true,
        becomes: 'maintenance',
    },
    resume: {
        title: 'Selesaikan perawatan',
        confirm: 'Selesai perawatan',
        reason: false,
        becomes: 'installed',
    },
    damage: { title: 'Tandai rusak', confirm: 'Tandai rusak', reason: true, becomes: 'damaged' },
    repair: {
        title: 'Selesai diperbaiki',
        confirm: 'Selesai diperbaiki',
        reason: false,
        becomes: 'available',
    },
    retire: { title: 'Pensiunkan aset', confirm: 'Pensiunkan', reason: true, becomes: 'retired' },
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-1 wrap-break-word text-foreground">{children}</dd>
        </div>
    );
}

export default function Show({ asset, locationOptions }: ShowProps) {
    const a = asset.data;
    const can = usePermission();
    const [actionModal, setActionModal] = useState<LifecycleAction | null>(null);
    const { data, setData, post, processing, reset, clearErrors, errors } = useForm({
        reason: '',
        location_id: '',
    });
    const step = actionModal ? LIFECYCLE[actionModal] : null;

    const closeModal = () => {
        setActionModal(null);
        reset();
        clearErrors();
    };

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;
        post(route(`admin.network-assets.${actionModal}`, a.id), { onSuccess: closeModal });
    };

    const stepButton = (action: LifecycleAction, primary = false, destructive = false) => (
        <Button
            type="button"
            variant={primary ? 'primary' : 'outline'}
            className={destructive ? 'text-destructive-text' : undefined}
            onClick={() => setActionModal(action)}
        >
            {LIFECYCLE[action].title}
        </Button>
    );

    return (
        <AdminLayout title={a.name}>
            <div className="space-y-6">
                <PageHeader
                    title={a.name}
                    subtitle={a.code}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.network-assets.index')}>Kembali</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('admin.network-assets.edit', a.id)}>Ubah</Link>
                            </Button>
                            {can('odn.view') && ['olt', 'odc', 'odp'].includes(a.asset_type) && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('admin.odn.assets.show', a.id)}>
                                        Port ODN
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Detail aset</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-4 text-sm md:grid-cols-2">
                                <Detail label="Status">
                                    <EnumBadge map={NETWORK_ASSET_STATUS} value={a.status} />
                                </Detail>
                                <Detail label="Tipe">
                                    <EnumBadge map={NETWORK_ASSET_TYPE} value={a.asset_type} />
                                </Detail>
                                <Detail label="Produk">
                                    {a.product ? (
                                        `${a.product.sku} - ${a.product.name}`
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </Detail>
                                <Detail label="Kepemilikan">
                                    <EnumBadge map={NETWORK_ASSET_OWNERSHIP} value={a.ownership} />
                                </Detail>
                                <Detail label="Nomor seri">
                                    {a.serial_number ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Alamat MAC">{a.mac_address ?? <EmptyCell />}</Detail>
                                <Detail label="Alamat IP">{a.ip_address ?? <EmptyCell />}</Detail>
                                <Detail label="IP manajemen">
                                    {a.management_ip ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Vendor">{a.vendor ?? <EmptyCell />}</Detail>
                                <Detail label="Model">{a.model ?? <EmptyCell />}</Detail>
                                <Detail label="Lokasi">{a.location?.name ?? <EmptyCell />}</Detail>
                                <Detail label="Jalur lokasi">
                                    {a.location?.path ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Pelanggan">
                                    {a.customer ? (
                                        <Link
                                            href={route('admin.customers.show', a.customer.id)}
                                            className="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                        >
                                            {a.customer.name}
                                        </Link>
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </Detail>
                                <Detail label="Langganan">
                                    {a.subscription ? (
                                        <Link
                                            href={route(
                                                'admin.subscriptions.show',
                                                a.subscription.id,
                                            )}
                                            className="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                        >
                                            {a.subscription.code}
                                        </Link>
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </Detail>
                                <Detail label="Dipasang">
                                    {a.installed_at ? (
                                        formatDateTime(a.installed_at)
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </Detail>
                                <Detail label="Dipensiunkan">
                                    {a.retired_at ? formatDateTime(a.retired_at) : <EmptyCell />}
                                </Detail>
                                {a.notes && (
                                    <div className="md:col-span-2">
                                        <Detail label="Catatan">{a.notes}</Detail>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Siklus hidup</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {a.status === 'available' && stepButton('install', true)}
                                {a.status === 'installed' && stepButton('remove')}
                                {a.status === 'installed' && stepButton('maintenance')}
                                {a.status === 'maintenance' && stepButton('resume', true)}
                                {(a.status === 'installed' || a.status === 'maintenance') &&
                                    stepButton('damage', false, true)}
                                {a.status === 'damaged' && stepButton('repair', true)}
                                {a.status !== 'retired' && stepButton('retire', false, true)}
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Riwayat pemasangan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Lokasi</TH>
                                    <TH>Dipasang</TH>
                                    <TH>Dilepas</TH>
                                    <TH>Alasan</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(a.installations ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={4}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            Belum ada riwayat pemasangan. Riwayat tercatat setiap
                                            kali aset dipasang dan dilepas.
                                        </TD>
                                    </TR>
                                ) : (
                                    (a.installations ?? []).map((i) => (
                                        <TR key={i.id}>
                                            <TD>{i.location?.name ?? <EmptyCell />}</TD>
                                            <TD className="text-sm">
                                                {formatDateTime(i.installed_at)}
                                            </TD>
                                            <TD className="text-sm">
                                                {i.removed_at ? (
                                                    formatDateTime(i.removed_at)
                                                ) : (
                                                    <EmptyCell />
                                                )}
                                            </TD>
                                            <TD>{i.removal_reason ?? <EmptyCell />}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
                <Modal open={step !== null} onClose={closeModal} title={step?.title}>
                    <form onSubmit={doAction} className="space-y-4">
                        {step && (
                            <p className="text-sm text-muted-foreground">
                                Status aset menjadi {labelOf(NETWORK_ASSET_STATUS, step.becomes)}.
                            </p>
                        )}
                        {actionModal === 'install' && (
                            <RemoteSearchSelect
                                field="location"
                                label="Lokasi"
                                value={data.location_id}
                                onChange={(value) => setData('location_id', value)}
                                options={locationOptions}
                                placeholder="Cari kode, nama, atau jalur lokasi"
                                emptyText="Tidak ada lokasi aktif yang cocok."
                                error={errors.location_id}
                                required
                            />
                        )}
                        {step?.reason && (
                            <Input
                                label="Alasan"
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                error={errors.reason}
                                maxLength={500}
                                required
                            />
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={closeModal}>
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                loading={processing}
                                disabled={actionModal === 'install' && data.location_id === ''}
                            >
                                {step?.confirm}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
