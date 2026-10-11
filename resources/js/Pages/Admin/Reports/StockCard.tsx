import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    FormulaVersionLabel,
    MetricDefinitionNote,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, Input } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import type { ReportMeta } from '@/types';
import { ReportExportMenu } from './components/ReportExportMenu';

interface Movement {
    id: number;
    movement_type: string;
    quantity: string;
    balance_after: string;
    from_location?: string | null;
    to_location?: string | null;
    note?: string | null;
    reference?: { label: string; url: string | null } | null;
    created_at: string;
}

interface Props extends Record<string, unknown> {
    movements?: PaginationLike<Movement> | null;
    filters: {
        product_id?: string;
        location_id?: string;
        date_from?: string;
        date_to?: string;
        sort_by?: string;
        sort_dir?: 'asc' | 'desc';
        per_page?: number;
    };
    meta: ReportMeta;
    can: { export: boolean };
    links?: { stocks: string | null; movements: string | null } | null;
}

export default function StockCard({ movements, filters, meta, can, links }: Props) {
    const pagination = movements ? toPagination(movements) : null;
    const table = useServerTable({
        url: route('admin.reports.stock-card'),
        filters: {
            product_id: filters.product_id,
            location_id: filters.location_id,
            date_from: filters.date_from,
            date_to: filters.date_to,
            sort: filters.sort_by,
            direction: filters.sort_dir,
            per_page: pagination?.per_page ?? filters.per_page ?? 10,
        },
        only: ['movements', 'filters'],
    });
    const [productId, setProductId] = useState(filters.product_id ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ product_id: productId, date_from: dateFrom, date_to: dateTo, page: 1 });
    };

    const columns: DataTableColumn<Movement>[] = [
        { key: 'movement_type', header: 'Type' },
        { key: 'quantity', header: 'Qty', sortable: true },
        { key: 'balance_after', header: 'Balance', sortable: true },
        { key: 'from_location', header: 'From', cell: (row) => row.from_location ?? '-' },
        { key: 'to_location', header: 'To', cell: (row) => row.to_location ?? '-' },
        { key: 'note', header: 'Note', cell: (row) => row.note ?? '-' },
        {
            key: 'reference',
            header: 'Reference',
            cell: (row) =>
                row.reference?.url ? (
                    <Link
                        href={row.reference.url}
                        className="text-primary underline-offset-2 hover:underline"
                    >
                        {row.reference.label}
                    </Link>
                ) : (
                    (row.reference?.label ?? '-')
                ),
        },
        { key: 'created_at', header: 'Date', sortable: true, className: 'text-sm' },
    ];

    return (
        <AdminLayout title="Stock Card">
            <div className="space-y-6">
                <PageHeader
                    title="Stock Card"
                    subtitle="Movement history per product/location."
                    actions={
                        <>
                            <FormulaVersionLabel version={meta.formula_version} />
                            {links?.stocks && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.stocks}>Stock levels</Link>
                                </Button>
                            )}
                            {links?.movements && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.movements}>All movements</Link>
                                </Button>
                            )}
                            <ReportExportMenu
                                url={route('admin.reports.stock-card.export')}
                                params={{
                                    product_id: filters.product_id,
                                    location_id: filters.location_id,
                                    date_from: filters.date_from,
                                    date_to: filters.date_to,
                                    sort_by: filters.sort_by,
                                    sort_dir: filters.sort_dir,
                                }}
                                canExport={can.export && Boolean(filters.product_id)}
                            />
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input
                                label="Product ID"
                                type="number"
                                value={productId}
                                onChange={(e) => setProductId(e.target.value)}
                                required
                            />
                            <Input
                                label="From"
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                            <Input
                                label="To"
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                            <div className="self-end">
                                <Button type="submit" disabled={table.visiting}>
                                    Run
                                </Button>
                            </div>
                        </form>
                        {filters.product_id && movements && pagination && (
                            <DataTable
                                columns={columns}
                                data={movements.data}
                                pagination={pagination}
                                rowKey="id"
                                sortBy={table.sortBy}
                                sortDir={table.sortDir}
                                onSort={table.onSort}
                                onPageChange={(page) => table.setPage(page)}
                                onPerPageChange={(perPage) => table.setPerPage(perPage)}
                                loading={table.processing}
                                emptyTitle="No stock movements"
                                emptyDescription="No movements match this product and date range. Adjust the filters and run the report again."
                            />
                        )}
                        {!movements && table.processing && (
                            <p role="status" aria-live="polite">
                                Loading stock movements…
                            </p>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Definitions</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {(['movements', 'quantity', 'balance_after'] as const).map((key) => (
                            <div key={key} className="min-w-0">
                                <p className="font-medium">{meta.metrics[key]?.label ?? key}</p>
                                <MetricDefinitionNote
                                    definition={meta.metrics[key]}
                                    className="mt-2 border-t-0 pt-0"
                                />
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
