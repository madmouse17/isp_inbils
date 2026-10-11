import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { DataTableActions, PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    code?: string | null;
    response_time_hours?: number | string | null;
    resolution_time_hours?: number | string | null;
    created_at: string | null;
};

type Props = {
    slaTiers: {
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

export default function SlaTiersIndex({ slaTiers, filters, can }: Props) {
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.sla-tiers.index'),
        filters,
        only: ['slaTiers', 'filters', 'can'],
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
                        href={route('admin.sla-tiers.edit', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'response_time_hours',
                header: 'Response (h)',
                sortable: true,
                sortKey: 'response_time_hours',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.response_time_hours ?? '—'),
            },
            {
                key: 'resolution_time_hours',
                header: 'Resolution (h)',
                sortable: true,
                sortKey: 'resolution_time_hours',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.resolution_time_hours ?? '—'),
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
                    <DataTableActions
                        editHref={route('admin.sla-tiers.edit', row.id)}
                        deleteHref={route('admin.sla-tiers.destroy', row.id)}
                        deleteMessage={`Delete ${row.name}?`}
                        deleteDescription={`This will permanently remove the SLA tier "${row.name}".`}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="SLA Tiers" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="SLA Tiers"
                    description="Response and resolution targets."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.sla-tiers.export')}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button asChild variant="success" size="sm">
                                    <Link href={route('admin.sla-tiers.create')}>
                                        <Plus className="size-4" />
                                        New tier
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
                            htmlFor="sla-search"
                        >
                            Search
                        </label>
                        <Input
                            id="sla-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Name…"
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(slaTiers)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    emptyTitle="No SLA tiers"
                    emptyDescription="No SLA tiers match the current filters."
                    loading={processing}
                />
            </div>
        </AppLayout>
    );
}
