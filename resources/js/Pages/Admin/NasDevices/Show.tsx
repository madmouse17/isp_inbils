import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
} from '@/Components/ui';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { ConfirmRequestDialog } from '@/Components/composite/ConfirmRequestDialog';
import { CopyButton } from '@/Components/composite/CopyButton';
import { NasStatusBadge } from '@/Components/composite/NetworkAccessBadges';
import { formatDateTime } from '@/lib/format';
import type { NasDevice, NasDeviceSession } from '@/types/network-asset';

interface ShowProps extends Record<string, unknown> {
    nasDevice: NasDevice;
    /** Open sessions on this router (first 100); empty while RADIUS is off or unreachable. */
    sessions: NasDeviceSession[];
    isolirUrl: string;
    radiusEnabled: boolean;
    can: { update: boolean };
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-1 wrap-break-word text-foreground">{children}</dd>
        </div>
    );
}

export default function Show({ nasDevice, sessions, isolirUrl, radiusEnabled, can }: ShowProps) {
    const [removing, setRemoving] = useState(false);

    const asset = nasDevice.network_asset;
    const assetCode = asset?.code ?? `#${nasDevice.id}`;

    const columns: DataTableColumn<NasDeviceSession>[] = useMemo(
        () => [
            {
                key: 'username',
                header: 'Nama pengguna',
                cell: (row) =>
                    row.subscription ? (
                        <Link
                            href={route(
                                'admin.subscriptions.network-account.show',
                                row.subscription.id,
                            )}
                            className="font-mono text-sm text-primary hover:underline"
                        >
                            {row.username}
                        </Link>
                    ) : (
                        <span className="font-mono text-sm">{row.username}</span>
                    ),
            },
            {
                key: 'framed_ip',
                header: 'Alamat',
                cell: (row) =>
                    row.framed_ip ? (
                        <span className="font-mono text-sm">{row.framed_ip}</span>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'started_at',
                header: 'Mulai',
                cell: (row) => formatDateTime(row.started_at),
            },
        ],
        [],
    );

    return (
        <AdminLayout title={`Router NAS ${assetCode}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Router NAS ${assetCode}`}
                    subtitle={`${asset?.name ?? 'Router'} - ${nasDevice.nasname}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.nas-devices.index')}>Kembali</Link>
                            </Button>
                            {can.update ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('admin.nas-devices.edit', nasDevice.id)}>
                                        Ubah
                                    </Link>
                                </Button>
                            ) : null}
                            {can.update ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="text-destructive-text"
                                    onClick={() => setRemoving(true)}
                                >
                                    Hapus router
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {!radiusEnabled ? (
                    <Alert variant="warning">
                        <AlertDescription>
                            RADIUS dimatikan untuk instalasi ini. Tidak ada yang ditulis ke
                            FreeRADIUS dan tidak ada sesi yang ditampilkan.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Alert variant="info">
                    <AlertDescription>
                        Mulai ulang FreeRADIUS setelah menambah NAS ini atau mengganti secret-nya.
                        FreeRADIUS hanya membaca kliennya saat mulai (sinyal reload tidak cukup).
                    </AlertDescription>
                </Alert>

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <Detail label="Klien RADIUS">
                                    <NasStatusBadge nas={nasDevice} />
                                </Detail>
                                <Detail label="Terakhir ditulis">
                                    {nasDevice.radius_synced_at
                                        ? formatDateTime(nasDevice.radius_synced_at)
                                        : 'Belum pernah'}
                                </Detail>
                                <Detail label="Alamat NAS">
                                    <span className="font-mono">{nasDevice.nasname}</span>
                                </Detail>
                                <Detail label="Target CoA">
                                    <span className="font-mono">
                                        {`${asset?.management_ip ?? '-'}:${nasDevice.coa_port}`}
                                    </span>
                                </Detail>
                                <Detail label="Realm">
                                    <span className="font-mono">{nasDevice.shortname}</span>
                                </Detail>
                                <Detail label="Secret RADIUS">
                                    {nasDevice.has_radius_secret ? 'Tersimpan' : 'Belum diisi'}
                                </Detail>
                            </dl>
                            {nasDevice.last_error ? (
                                <Alert variant="danger">
                                    <AlertTitle>Kesalahan terakhir</AlertTitle>
                                    <AlertDescription className="wrap-break-word">
                                        {nasDevice.last_error}
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Halaman isolir</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Router harus mengarahkan pelanggan di grup isolir ke alamat ini.
                            </p>
                            <code className="block rounded-md bg-muted p-2.5 font-mono text-xs break-all text-foreground select-all">
                                {isolirUrl}
                            </code>
                            <CopyButton value={isolirUrl} label="Salin URL" />
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Sesi terbuka</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={columns}
                            data={sessions}
                            emptyTitle="Tidak ada sesi terbuka"
                            emptyDescription={
                                radiusEnabled
                                    ? 'Tidak ada pelanggan yang tersambung lewat router ini saat ini.'
                                    : 'Nyalakan RADIUS untuk melihat sesi yang terbuka di router ini.'
                            }
                        />
                    </CardContent>
                </Card>

                {removing ? (
                    <ConfirmRequestDialog
                        request={{
                            title: 'Hapus router NAS ini?',
                            description:
                                'Baris klien dihapus dari FreeRADIUS. Router mengakhiri sesi yang terbuka dengan sendirinya.',
                            confirmLabel: 'Hapus',
                            href: route('admin.nas-devices.destroy', nasDevice.id),
                        }}
                        onClose={() => setRemoving(false)}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}
