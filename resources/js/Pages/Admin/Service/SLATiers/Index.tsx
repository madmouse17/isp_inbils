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
    response_minutes?: number | string | null;
    resolve_minutes?: number | string | null;
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
    const { params, set, sortBy, sortDir, onSort } = useServerTable({
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
                key: 'response_minutes',
                header: 'Response (min)',
                sortable: true,
                sortKey: 'response_minutes',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.response_minutes ?? '—'),
            },
            {
                key: 'resolve_minutes',
                header: 'Resolve (min)',
                sortable: true,
                sortKey: 'resolve_minutes',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.resolve_minutes ?? '—'),
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
                            href={route('admin.sla-tiers.edit', row.id)}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Edit
                        </Link>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                if (window.confirm(`Delete ${row.name}?`)) {
                                    router.delete(route('admin.sla-tiers.destroy', row.id));
                                }
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
                                <Button asChild size="sm">
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
                    loading={false}
                />
            </div>
        </AppLayout>
    );
}
