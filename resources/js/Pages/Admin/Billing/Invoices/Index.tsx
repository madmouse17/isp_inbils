import { Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { DataTableActions } from '@/Components/composite/DataTableActions';
import { StatusFilter } from '@/Components/composite/StatusFilter';
import { RemoteSearchSelect, type RemoteOption } from '@/Components/composite/RemoteSearchSelect';
import { Button } from '@/Components/ui/Button';
import { DateRangePicker, EmptyCell, EnumBadge, ExportMenu, Input } from '@/Components/ui';
import GenerateDialog from '@/Pages/Admin/Billing/Invoices/GenerateDialog';
import { useCan } from '@/hooks/useCan';
import { useCompany } from '@/hooks/useCompany';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDate, formatRupiah } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { INVOICE_STATUS, LIFECYCLE_STATE } from '@/lib/statuses';

type InvoiceRow = {
    id: string;
    number: string;
    status: string;
    issue_date: string | null;
    due_date: string | null;
    total: string | number;
    paid_amount: string | number;
    sisa: string | number;
    customer?: { id: string; name: string; code: string } | null;
    deleted_at?: string | null;
};

type Props = {
    invoices: PaginationLike<InvoiceRow>;
    filters: {
        search?: string;
        status?: string;
        customer_id?: string;
        date_from?: string;
        date_to?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
    customerOptions?: RemoteOption[];
    selectedCustomer: RemoteOption | null;
};

export default function InvoicesIndex({
    invoices,
    filters,
    customerOptions,
    selectedCustomer,
}: Props) {
    const can = useCan();
    const company = useCompany();
    const { errors } = usePage().props as { errors?: Record<string, string | undefined> };
    const [generateOpen, setGenerateOpen] = useState(false);
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            status: filters.status ?? '',
            customer_id: filters.customer_id ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? toPagination(invoices).per_page,
        },
        { only: ['invoices', 'filters', 'selectedCustomer'] },
    );

    const hasFilters = Boolean(
        params.search || params.status || params.customer_id || params.date_from || params.date_to,
    );

    const columns: DataTableColumn<InvoiceRow>[] = useMemo(
        () => [
            {
                key: 'number',
                header: 'Nomor tagihan',
                sortable: true,
                sortKey: 'number',
                cell: (row) =>
                    row.deleted_at ? (
                        <span className="font-medium text-muted-foreground">{row.number}</span>
                    ) : (
                        <Link
                            href={route('admin.invoices.show', row.id)}
                            className="font-medium text-primary-text hover:underline"
                        >
                            {row.number}
                        </Link>
                    ),
            },
            {
                key: 'customer',
                header: 'Pelanggan',
                cell: (row) =>
                    row.customer ? (
                        <div>
                            <div className="font-medium">{row.customer.name}</div>
                            <div className="text-xs text-muted-foreground">{row.customer.code}</div>
                        </div>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) =>
                    row.deleted_at ? (
                        <EnumBadge map={LIFECYCLE_STATE} value="dihapus" />
                    ) : (
                        <EnumBadge map={INVOICE_STATUS} value={row.status} />
                    ),
            },
            {
                key: 'issue_date',
                header: 'Tanggal terbit',
                sortable: true,
                sortKey: 'issue_date',
                cell: (row) => formatDate(row.issue_date, company),
            },
            {
                key: 'due_date',
                header: 'Jatuh tempo',
                sortable: true,
                sortKey: 'due_date',
                cell: (row) => formatDate(row.due_date, company),
            },
            {
                key: 'total',
                header: 'Total',
                sortable: true,
                sortKey: 'total',
                className: 'text-right tabular-nums',
                cell: (row) => formatRupiah(row.total, company),
            },
            {
                key: 'sisa',
                header: 'Sisa',
                className: 'text-right tabular-nums',
                cell: (row) => formatRupiah(row.sisa, company),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <DataTableActions
                        showHref={route('admin.invoices.show', row.id)}
                        lifecycle={{
                            row,
                            name: row.number,
                            noun: 'Tagihan',
                            routeBase: 'admin.invoices',
                            can: { delete: can('billing.delete') },
                        }}
                    />
                ),
            },
        ],
        [company, can],
    );

    return (
        <AdminLayout title="Tagihan">
            <div className="space-y-4">
                <PageHeader
                    title="Tagihan"
                    description="Daftar tagihan pelanggan beserta sisa yang harus dibayar."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.invoices.export')}
                                params={params}
                                canExport={can('billing.export')}
                                formats={['csv', 'pdf']}
                            />
                            {can('billing.create') && (
                                <>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setGenerateOpen(true)}
                                    >
                                        Buat tagihan bulanan
                                    </Button>
                                    <Button asChild size="sm" variant="primary">
                                        <Link href={route('admin.invoices.create')}>
                                            <Plus className="size-4" />
                                            Buat tagihan
                                        </Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="inv-search"
                            label="Cari tagihan"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Nomor tagihan atau pelanggan"
                        />
                    </div>
                    <StatusFilter
                        id="inv-status"
                        value={params.status ?? ''}
                        onChange={(value) => set('status', value)}
                        statuses={INVOICE_STATUS}
                        wrapperClassName="md:w-52"
                    />
                    <div className="w-full md:w-64">
                        <RemoteSearchSelect
                            id="inv-customer"
                            field="customer"
                            label="Pelanggan"
                            placeholder="Semua pelanggan"
                            options={customerOptions}
                            selected={selectedCustomer}
                            value={params.customer_id ?? ''}
                            onChange={(value) => set('customer_id', value)}
                        />
                    </div>
                    <div className="w-full md:w-80">
                        <DateRangePicker
                            id="inv-range"
                            error={errors?.date_from ?? errors?.date_to}
                            label="Periode"
                            value={{ from: params.date_from || null, to: params.date_to || null }}
                            onChange={(range) => {
                                set('date_from', range.from);
                                set('date_to', range.to);
                            }}
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={invoices.data}
                    itemLabel="tagihan"
                    emptyTitle="Belum ada tagihan"
                    emptyDescription="Tagihan muncul di sini setelah dibuat secara manual, dari SPK, atau lewat tagihan bulanan."
                    emptyAction={
                        can('billing.create') ? (
                            <Button asChild size="sm" variant="primary">
                                <Link href={route('admin.invoices.create')}>
                                    <Plus className="size-4" />
                                    Buat tagihan
                                </Link>
                            </Button>
                        ) : undefined
                    }
                    hasFilters={hasFilters}
                    onResetFilters={() =>
                        setMany({
                            search: '',
                            status: '',
                            customer_id: '',
                            date_from: '',
                            date_to: '',
                        })
                    }
                    loading={processing}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={toPagination(invoices)}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
                <GenerateDialog open={generateOpen} onClose={() => setGenerateOpen(false)} />
            </div>
        </AdminLayout>
    );
}
