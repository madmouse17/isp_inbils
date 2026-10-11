import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, Input } from '@/Components/ui';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { NasStatusBadge, WritesBadge } from '@/Components/composite/NetworkAccessBadges';
import { ToneBadge } from '@/Components/composite/ToneBadge';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDateTime } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import type { NasDevice } from '@/types/network-asset';

interface IndexProps extends Record<string, unknown> {
    nasDevices: PaginationLike<NasDevice>;
    filters: {
        search?: string | null;
        sort_by?: string | null;
        sort_dir?: string | null;
        per_page?: string | number | null;
    };
    can: { create: boolean };
}

export default function Index({ nasDevices, filters, can }: IndexProps) {
    const pagination = toPagination(nasDevices);
    const { params, set, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? pagination.per_page,
        },
        { only: ['nasDevices', 'filters'] },
    );

    const columns: DataTableColumn<NasDevice>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Router',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <Link
                            href={route('admin.nas-devices.show', row.id)}
                            className="font-medium text-primary hover:underline"
                        >
                            {row.network_asset?.code ?? `Router #${row.id}`}
                        </Link>
                        {row.network_asset?.name ? (
                            <p className="text-xs text-muted-foreground">
                                {row.network_asset.name}
                            </p>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'location',
                header: 'Location',
                cell: (row) => row.network_asset?.location?.name ?? '—',
            },
            {
                key: 'management_ip',
                header: 'Management IP',
                cell: (row) => (
                    <span className="font-mono text-sm">
                        {row.network_asset?.management_ip ?? '—'}
                    </span>
                ),
            },
            {
                key: 'provisioning_enabled',
                header: 'Writes',
                cell: (row) => <WritesBadge enabled={row.provisioning_enabled} />,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <NasStatusBadge nas={row} />,
            },
            {
                key: 'routeros_version',
                header: 'RouterOS',
                sortable: true,
                sortKey: 'routeros_version',
                cell: (row) => row.routeros_version ?? '—',
            },
            {
                key: 'accounts_count',
                header: 'Accounts',
                cell: (row) => (
                    <div className="flex items-center gap-2">
                        <span>{row.accounts_count}</span>
                        {row.accounts_failed_count > 0 ? (
                            <ToneBadge tone="danger">{row.accounts_failed_count} failed</ToneBadge>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'last_checked_at',
                header: 'Last check',
                sortable: true,
                sortKey: 'last_checked_at',
                cell: (row) =>
                    row.last_checked_at ? formatDateTime(row.last_checked_at) : 'Never',
            },
            {
                key: 'actions',
                header: 'Actions',
                className: 'text-right',
                cell: (row) => (
                    <DataTableActions
                        showHref={route('admin.nas-devices.show', row.id)}
                        editHref={can.create ? route('admin.nas-devices.edit', row.id) : undefined}
                    />
                ),
            },
        ],
        [can.create],
    );

    const registerLink = (
        <Button asChild size="sm">
            <Link href={route('admin.nas-devices.create')}>Register router</Link>
        </Button>
    );

    return (
        <AdminLayout title="NAS Routers">
            <div className="space-y-6">
                <PageHeader
                    title="NAS Routers"
                    subtitle="MikroTik routers Inbils provisions PPPoE and hotspot accounts on."
                    actions={can.create ? registerLink : undefined}
                />

                <Card>
                    <CardContent className="pt-6">
                        <div className="max-w-md">
                            <Input
                                id="nas-search"
                                label="Search"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Code, name or management IP"
                            />
                        </div>
                    </CardContent>
                </Card>

                <DataTable
                    columns={columns}
                    data={nasDevices.data}
                    emptyTitle={
                        params.search ? 'No NAS routers match your search' : 'No NAS routers'
                    }
                    emptyDescription="Register a router asset to provision PPPoE and hotspot accounts on it."
                    emptyAction={can.create && !params.search ? registerLink : undefined}
                    loading={processing}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={pagination}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                />
            </div>
        </AdminLayout>
    );
}
