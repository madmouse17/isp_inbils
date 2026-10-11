import { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
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
import {
    NasStatusBadge,
    OnlineBadge,
    serviceTypeLabel,
    SyncStatusBadge,
    WritesBadge,
} from '@/Components/composite/NetworkAccessBadges';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDateTime } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import type { NasDevice, NasDeviceAccount } from '@/types/network-asset';

interface ShowProps extends Record<string, unknown> {
    nasDevice: NasDevice;
    accounts: PaginationLike<NasDeviceAccount>;
    /** At most the first 100 secrets on the router that no network account manages. */
    unmanagedUsernames: string[];
    isolirUrl: string;
    installEnabled: boolean;
    can: { update: boolean; check: boolean };
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-1 wrap-break-word text-foreground">{children}</dd>
        </div>
    );
}

export default function Show({
    nasDevice,
    accounts,
    unmanagedUsernames,
    isolirUrl,
    installEnabled,
    can,
}: ShowProps) {
    const [checking, setChecking] = useState(false);
    const [removing, setRemoving] = useState(false);

    const asset = nasDevice.network_asset;
    const assetCode = asset?.code ?? `#${nasDevice.id}`;
    const hasAccounts = nasDevice.accounts_count > 0;
    const pagination = toPagination(accounts);
    const table = useServerTable({
        url: route('admin.nas-devices.show', nasDevice.id),
        filters: { per_page: pagination.per_page },
        only: ['accounts'],
    });

    const columns: DataTableColumn<NasDeviceAccount>[] = useMemo(
        () => [
            {
                key: 'subscription',
                header: 'Subscription',
                cell: (row) => (
                    <Link
                        href={route(
                            'admin.subscriptions.network-account.show',
                            row.subscription.id,
                        )}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.subscription.code}
                    </Link>
                ),
            },
            {
                key: 'customer',
                header: 'Customer',
                cell: (row) => row.subscription.customer_name ?? '—',
            },
            {
                key: 'service_type',
                header: 'Type',
                cell: (row) => serviceTypeLabel[row.service_type],
            },
            {
                key: 'username',
                header: 'Username',
                cell: (row) => <span className="font-mono text-sm">{row.username}</span>,
            },
            {
                key: 'sync_status',
                header: 'Sync',
                cell: (row) => (
                    <div className="space-y-1">
                        <SyncStatusBadge status={row.sync_status} />
                        {row.last_error ? (
                            <p
                                className={
                                    row.sync_status === 'failed'
                                        ? 'max-w-64 text-xs wrap-break-word text-red-700 dark:text-red-400'
                                        : 'max-w-64 text-xs wrap-break-word text-muted-foreground'
                                }
                            >
                                {row.last_error}
                            </p>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'provisioned_profile',
                header: 'Profile',
                cell: (row) => row.provisioned_profile ?? '—',
            },
            {
                key: 'online',
                header: 'Online',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <OnlineBadge account={row} />
                        {row.current_address ? (
                            <p className="font-mono text-xs text-muted-foreground">
                                {row.current_address}
                            </p>
                        ) : null}
                    </div>
                ),
            },
        ],
        [],
    );

    const checkNow = () =>
        router.post(
            route('admin.nas-devices.check', nasDevice.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );

    return (
        <AdminLayout title={`NAS router ${assetCode}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`NAS router ${assetCode}`}
                    subtitle={`${asset?.name ?? 'Router'} - ${asset?.management_ip ?? 'no management IP'}`}
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
                            {can.check ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    loading={checking}
                                    onClick={checkNow}
                                >
                                    Check now
                                </Button>
                            ) : null}
                            {can.update ? (
                                <Button
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    disabled={hasAccounts}
                                    aria-describedby={
                                        hasAccounts ? 'remove-router-blocked' : undefined
                                    }
                                    onClick={() => setRemoving(true)}
                                >
                                    Remove router
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {can.update && hasAccounts ? (
                    <p id="remove-router-blocked" className="text-sm text-muted-foreground">
                        Network accounts must be moved to another router first.
                    </p>
                ) : null}

                {!installEnabled ? (
                    <Alert variant="warning">
                        <AlertDescription>
                            Provisioning is switched off for this installation. Nothing is sent to
                            routers, so accounts stay pending.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <Detail label="Reachability">
                                    <NasStatusBadge nas={nasDevice} />
                                </Detail>
                                <Detail label="RouterOS version">
                                    {nasDevice.routeros_version ?? '—'}
                                </Detail>
                                <Detail label="Last check">
                                    {nasDevice.last_checked_at
                                        ? formatDateTime(nasDevice.last_checked_at)
                                        : 'Never'}
                                </Detail>
                                <Detail label="Last seen">
                                    {nasDevice.last_seen_at
                                        ? formatDateTime(nasDevice.last_seen_at)
                                        : 'Never'}
                                </Detail>
                                <Detail label="Writes">
                                    <WritesBadge enabled={nasDevice.provisioning_enabled} />
                                </Detail>
                                <Detail label="Management address">
                                    <span className="font-mono">
                                        {`${asset?.management_ip ?? '—'}:${nasDevice.api_port}`}
                                    </span>
                                </Detail>
                                <Detail label="API user">
                                    <span className="font-mono">{nasDevice.api_username}</span>
                                </Detail>
                                <Detail label="Isolir profile">
                                    <span className="font-mono">{nasDevice.isolir_profile}</span>
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
                                Subscribers on the isolir profile should be redirected to this
                                address by the router.
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
                        <CardTitle>Network accounts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={columns}
                            data={accounts.data}
                            pagination={pagination}
                            loading={table.processing}
                            onPageChange={(page) => table.visit({ page })}
                            onPerPageChange={(perPage) =>
                                table.visit({ per_page: perPage, page: 1 })
                            }
                            emptyTitle="No network accounts on this router"
                            emptyDescription="Create one from the Network access page of a subscription."
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Unmanaged secrets</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {nasDevice.last_checked_at === null ? (
                            <p className="text-muted-foreground">Not checked yet.</p>
                        ) : nasDevice.unmanaged_count === 0 ? (
                            <p className="text-muted-foreground">None found.</p>
                        ) : (
                            <>
                                <p className="text-foreground">
                                    {nasDevice.unmanaged_count === 1
                                        ? '1 secret on this router is not managed by Inbils.'
                                        : `${nasDevice.unmanaged_count} secrets on this router are not managed by Inbils.`}
                                </p>
                                {unmanagedUsernames.length > 0 ? (
                                    <ul className="flex flex-wrap gap-1.5">
                                        {unmanagedUsernames.map((username) => (
                                            <li
                                                key={username}
                                                className="rounded-md bg-muted px-2 py-0.5 font-mono text-xs break-all text-foreground"
                                            >
                                                {username}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                {unmanagedUsernames.length < nasDevice.unmanaged_count ? (
                                    <p className="text-xs text-muted-foreground">
                                        Showing the first {unmanagedUsernames.length} of{' '}
                                        {nasDevice.unmanaged_count}.
                                    </p>
                                ) : null}
                            </>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Create a network account with the same username to adopt a secret, or
                            remove it on the router.
                        </p>
                    </CardContent>
                </Card>

                {removing ? (
                    <ConfirmRequestDialog
                        request={{
                            title: 'Remove this NAS router?',
                            description:
                                'Network accounts must be moved to another router first. The router itself is not changed.',
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
