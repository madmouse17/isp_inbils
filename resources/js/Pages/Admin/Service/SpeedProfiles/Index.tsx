import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    code?: string | null;
    download_mbps?: number | string | null;
    upload_mbps?: number | string | null;
    created_at: string | null;
};

type Props = {
    speedProfiles: {
        data: Row[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
};

export default function SpeedProfilesIndex({ speedProfiles, filters, can }: Props) {
    const { params, set, sortBy, sortDir, onSort } = useServerTable({
        url: route('admin.speed-profiles.index'),
        filters,
        only: ['speedProfiles', 'filters', 'can'],
    });

    const columns: DataTableColumn<Row>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
                cell: (row) => (
                    <Link
                        href={route('admin.speed-profiles.edit', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'download_mbps',
                header: 'Download (Mbps)',
                sortable: true,
                sortKey: 'download_mbps',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.download_mbps ?? '—'),
            },
            {
                key: 'upload_mbps',
                header: 'Upload (Mbps)',
                sortable: true,
                sortKey: 'upload_mbps',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.upload_mbps ?? '—'),
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
                cell: (row) => (
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={route('admin.speed-profiles.edit', row.id)}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Edit
                        </Link>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                if (window.confirm(`Delete ${row.name}?`))
                                    router.delete(route('admin.speed-profiles.destroy', row.id));
                            }}
                        >
                            Delete
                        </Button>
                    </div>
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Speed Profiles" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Speed Profiles"
                    description="Marketing speed labels linked to packages."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.speed-profiles.export')}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button asChild size="sm">
                                    <Link href={route('admin.speed-profiles.create')}>
                                        <Plus className="size-4" />
                                        New profile
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="sp-search"
                        >
                            Search
                        </label>
                        <Input
                            id="sp-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Name…"
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(speedProfiles)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    emptyTitle="No speed profiles"
                    emptyDescription="No speed profiles match the current filters."
                    loading={false}
                />
            </div>
        </AppLayout>
    );
}
