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
                header: 'Username',
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
                header: 'Address',
                cell: (row) => <span className="font-mono text-sm">{row.framed_ip ?? '-'}</span>,
            },
            {
                key: 'started_at',
                header: 'Started',
                cell: (row) => formatDateTime(row.started_at),
            },
        ],
        [],
    );

    return (
        <AdminLayout title={`NAS router ${assetCode}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`NAS router ${assetCode}`}
                    subtitle={`${asset?.name ?? 'Router'} - ${nasDevice.nasname}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.nas-devices.index')}>Back</Link>
                            </Button>
                            {can.update ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('admin.nas-devices.edit', nasDevice.id)}>
                                        Edit
                                    </Link>
                                </Button>
                            ) : null}
                            {can.update ? (
                                <Button
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    onClick={() => setRemoving(true)}
                                >
                                    Remove router
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {!radiusEnabled ? (
                    <Alert variant="warning">
                        <AlertDescription>
                            RADIUS is switched off for this installation. Nothing is written to
                            FreeRADIUS and no session is listed.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Alert variant="info">
                    <AlertDescription>
                        Restart FreeRADIUS after adding this NAS or changing its secret. FreeRADIUS
                        reads its clients only at start (a reload signal is not enough).
                    </AlertDescription>
                </Alert>

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <Detail label="RADIUS client">
                                    <NasStatusBadge nas={nasDevice} />
                                </Detail>
                                <Detail label="Last written">
                                    {nasDevice.radius_synced_at
                                        ? formatDateTime(nasDevice.radius_synced_at)
                                        : 'Never'}
                                </Detail>
                                <Detail label="NAS address">
                                    <span className="font-mono">{nasDevice.nasname}</span>
                                </Detail>
                                <Detail label="CoA target">
                                    <span className="font-mono">
                                        {`${asset?.management_ip ?? '-'}:${nasDevice.coa_port}`}
                                    </span>
                                </Detail>
                                <Detail label="Realm">
                                    <span className="font-mono">{nasDevice.shortname}</span>
                                </Detail>
                                <Detail label="RADIUS secret">
                                    {nasDevice.has_radius_secret ? 'Stored' : 'Not set'}
                                </Detail>
                            </dl>
                            {nasDevice.last_error ? (
                                <Alert variant="danger">
                                    <AlertTitle className="text-red-700 dark:text-red-400">
                                        Last error
                                    </AlertTitle>
                                    <AlertDescription className="wrap-break-word text-red-700 dark:text-red-400">
                                        {nasDevice.last_error}
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Isolir page</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Subscribers in the isolir group should be redirected to this address
                                by the router.
                            </p>
                            <code className="block rounded-md bg-muted p-2.5 font-mono text-xs break-all text-foreground select-all">
                                {isolirUrl}
                            </code>
                            <CopyButton value={isolirUrl} label="Copy URL" />
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Open sessions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={columns}
                            data={sessions}
                            emptyTitle="No open sessions"
                            emptyDescription={
                                radiusEnabled
                                    ? 'No subscriber is connected through this router right now.'
                                    : 'Switch RADIUS on to see the sessions open on this router.'
                            }
                        />
                    </CardContent>
                </Card>

                {removing ? (
                    <ConfirmRequestDialog
                        request={{
                            title: 'Remove this NAS router?',
                            description:
                                'The client row is removed from FreeRADIUS. The router ends open sessions on its own.',
                            confirmLabel: 'Remove',
                            href: route('admin.nas-devices.destroy', nasDevice.id),
                        }}
                        onClose={() => setRemoving(false)}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}
