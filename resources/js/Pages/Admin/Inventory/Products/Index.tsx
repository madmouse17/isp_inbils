import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatMoney } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { ACTIVE_STATE, optionsOf } from '@/lib/statuses';
import {
    Button,
    Card,
    CardContent,
    EmptyCell,
    EnumBadge,
    Input,
    NativeSelect,
    SearchSelect,
} from '@/Components/ui';
import type { Category, Product } from '@/types/inventory';

interface IndexProps extends Record<string, unknown> {
    products: PaginationLike<Product>;
    categories: { data: Category[] };
    filters: {
        category_id?: string;
        is_active?: string;
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: number;
    };
    can: { create: boolean; export: boolean };
}

const ACTIVE_OPTIONS = [{ value: '', label: 'Semua status' }, ...optionsOf(ACTIVE_STATE)];

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
    const [isActive, setIsActive] = useState(filters.is_active ?? '');

    const categoryChoices = categories.data.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
    }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ search, category_id: categoryId, is_active: isActive });
    };

    const resetFilters = () => {
        setSearch('');
        setCategoryId('');
        setIsActive('');
        table.visit({ search: '', category_id: '', is_active: '' });
    };

    const columns: DataTableColumn<Product>[] = [
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
                <Link
                    href={route('admin.products.show', p.id)}
                    className="font-medium text-primary-text hover:underline"
                >
                    {p.name}
                </Link>
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
            key: 'is_active',
            header: 'Status',
            cell: (p) => <EnumBadge map={ACTIVE_STATE} value={p.is_active} />,
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (p) => (
                <DataTableActions
                    showHref={route('admin.products.show', p.id)}
                    editHref={route('admin.products.edit', p.id)}
                    deleteHref={route('admin.products.destroy', p.id)}
                    deleteMessage={`Hapus ${p.name}?`}
                    deleteDescription={`Barang "${p.name}" akan dihapus dari daftar. Barang yang masih punya stok tidak bisa dihapus.`}
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
                            <NativeSelect
                                label="Status"
                                value={isActive}
                                onChange={(e) => setIsActive(e.target.value)}
                                options={ACTIVE_OPTIONS}
                                wrapperClassName="sm:w-44"
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
                                filters.search || filters.category_id || filters.is_active,
                            )}
                            onResetFilters={resetFilters}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
