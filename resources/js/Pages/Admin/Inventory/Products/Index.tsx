import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    StatusFilter,
    type DataTableColumn,
} from '@/Components/composite';
import { UsageLine } from '@/Components/composite/UsageLine';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatMoney } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { LIFECYCLE_STATE, lifecycleStateOf } from '@/lib/statuses';
import {
    Button,
    Card,
    CardContent,
    EmptyCell,
    EnumBadge,
    Input,
    SearchSelect,
} from '@/Components/ui';
import type { LifecycleFields } from '@/types/models';
import type { Category, Product } from '@/types/inventory';

type ProductRow = Product & LifecycleFields;

interface IndexProps extends Record<string, unknown> {
    products: PaginationLike<ProductRow>;
    categories: { data: Category[] };
    filters: {
        category_id?: string;
        status?: string;
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: number;
    };
    can: { create: boolean; export: boolean; update: boolean; delete: boolean };
}

export default function Index({ products, categories, filters, can }: IndexProps) {
    const company = useCompany();
    const pagination = toPagination(products);
    const table = useServerTable({
        url: route('admin.products.index'),
        filters: { ...filters, per_page: pagination.per_page },
        only: ['products', 'filters'],
    });
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');

    const categoryChoices = categories.data.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
    }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ search, category_id: categoryId });
    };

    const resetFilters = () => {
        setSearch('');
        setCategoryId('');
        table.visit({ search: '', category_id: '', status: '' });
    };

    const columns: DataTableColumn<ProductRow>[] = [
        {
            key: 'sku',
            header: 'SKU',
            sortable: true,
            cell: (p) => <span className="font-mono text-sm">{p.sku}</span>,
        },
        {
            key: 'name',
            header: 'Nama',
            sortable: true,
            cell: (p) => (
                <div className="space-y-0.5">
                    {p.deleted_at ? (
                        <span className="font-medium text-muted-foreground">{p.name}</span>
                    ) : (
                        <Link
                            href={route('admin.products.show', p.id)}
                            className="font-medium text-primary-text hover:underline"
                        >
                            {p.name}
                        </Link>
                    )}
                    <UsageLine references={p.references} />
                </div>
            ),
        },
        { key: 'category', header: 'Kategori', cell: (p) => p.category?.name ?? <EmptyCell /> },
        {
            key: 'unit',
            header: 'Satuan',
            cell: (p) => (p.unit ? `${p.unit.name} (${p.unit.symbol})` : <EmptyCell />),
        },
        {
            key: 'sell_price',
            header: 'Harga Jual',
            className: 'text-right tabular-nums',
            cell: (p) =>
                p.sell_price === null || p.sell_price === undefined ? (
                    <EmptyCell />
                ) : (
                    formatMoney(p.sell_price, company)
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (p) => <EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(p)} />,
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (p) => (
                <DataTableActions
                    showHref={route('admin.products.show', p.id)}
                    editHref={route('admin.products.edit', p.id)}
                    lifecycle={{
                        row: p,
                        name: p.name,
                        noun: 'Barang',
                        routeBase: 'admin.products',
                        can: { update: can.update, delete: can.delete },
                    }}
                />
            ),
        },
    ];

    const createButton = can.create ? (
        <Button asChild variant="primary">
            <Link href={route('admin.products.create')}>Buat Barang</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Barang">
            <div className="space-y-6">
                <PageHeader
                    title="Barang"
                    subtitle="Data induk barang: SKU, kategori, satuan, dan harga jual."
                    actions={
                        <>
                            <ExportMenu
                                exportUrl={route('admin.products.export')}
                                params={table.params}
                                canExport={can.export}
                                formats={['csv', 'pdf']}
                            />
                            {createButton}
                        </>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                            <Input
                                label="Cari barang"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="SKU atau nama"
                            />
                            <div className="w-full sm:w-64">
                                <SearchSelect
                                    id="product-category"
                                    label="Kategori"
                                    placeholder="Semua kategori"
                                    options={categoryChoices}
                                    value={categoryId}
                                    onChange={setCategoryId}
                                />
                            </div>
                            <StatusFilter
                                id="product-status"
                                value={table.params.status ?? ''}
                                onChange={(value) => table.set('status', value)}
                                deactivatable
                            />
                            <Button type="submit" variant="secondary">
                                Filter
                            </Button>
                        </form>
                        <DataTable
                            columns={columns}
                            data={products.data}
                            pagination={pagination}
                            loading={table.processing}
                            sortBy={table.sortBy}
                            sortDir={table.sortDir}
                            onSort={table.onSort}
                            onPageChange={table.setPage}
                            onPerPageChange={table.setPerPage}
                            itemLabel="barang"
                            emptyDescription="Barang yang dibuat muncul di sini, lengkap dengan kategori dan satuannya."
                            emptyAction={createButton}
                            hasFilters={Boolean(
                                filters.search || filters.category_id || filters.status,
                            )}
                            onResetFilters={resetFilters}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
