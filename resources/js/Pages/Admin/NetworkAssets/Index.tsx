import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { DataTableActions } from '@/Components/composite/DataTableActions';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useCan } from '@/hooks/useCan';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDate } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';

type AssetRow = {
    id: number;
    code: string;
    name: string;
    asset_type: string;
    status: string;
    serial_number?: string | null;
    created_at: string | null;
    location?: { id: number; name: string } | null;
    parent?: { id: number; name: string; code?: string } | null;
};

type Props = {
    assets: PaginationLike<AssetRow>;
    filters: {
        search?: string;
        asset_type?: string;
        status?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
    typeOptions?: string[];
    statusOptions?: string[];
};

export default function NetworkAssetsIndex({
    assets,
    filters,
    typeOptions = [],
    statusOptions = [],
}: Props) {
    const can = useCan();
    const pagination = toPagination(assets);
    const { params, set, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            asset_type: filters.asset_type ?? '',
            status: filters.status ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? pagination.per_page,
        },
        { only: ['assets', 'filters'] },
    );

    const columns: DataTableColumn<AssetRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <Link
                        href={route('admin.network-assets.show', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
                cell: (row) => row.name,
            },
            {
                key: 'asset_type',
                header: 'Type',
                sortable: true,
                sortKey: 'asset_type',
                cell: (row) => row.asset_type,
            },
            {
                key: 'serial_number',
                header: 'Serial',
                cell: (row) =>
                    row.serial_number ? (
                        <span className="font-mono text-sm">{row.serial_number}</span>
                    ) : (
                        '—'
                    ),
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => <StatusBadge status={row.status} />,
            },
            {
                key: 'location',
                header: 'Location',
                cell: (row) => row.location?.name ?? '—',
            },
            {
                key: 'created_at',
                header: 'Created',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDate(row.created_at),
            },
            {
                key: 'actions',
                header: 'Actions',
                className: 'text-right',
                cell: (row) => (
                    <DataTableActions showHref={route('admin.network-assets.show', row.id)} />
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Network Assets" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Network Assets"
                    description="Physical and logical network inventory."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.network-assets.export')}
                                params={params}
                                canExport={can('network_asset.export')}
                                formats={['csv', 'pdf']}
                            />
                            {can('network_asset.create') && (
                                <Button asChild size="sm" variant="success">
                                    <Link href={route('admin.network-assets.create')}>
                                        <Plus className="size-4" />
                                        New asset
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="na-search"
                        >
                            Search
                        </label>
                        <Input
                            id="na-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Code, name, serial…"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Type</label>
                        <Select
                            value={params.asset_type || '__all__'}
                            onValueChange={(v) => set('asset_type', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger aria-label="Type" className="w-[10rem]">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {typeOptions.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Status</label>
                        <Select
                            value={params.status || '__all__'}
                            onValueChange={(v) => set('status', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger aria-label="Status" className="w-[10rem]">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {statusOptions.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={assets.data}
                    emptyTitle="No network assets"
                    emptyDescription="No assets match the current filters."
                    loading={processing}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={pagination}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AppLayout>
    );
}
