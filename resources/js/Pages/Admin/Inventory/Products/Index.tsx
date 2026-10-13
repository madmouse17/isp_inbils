import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { Badge, Button, Card, CardContent, Input, NativeSelect } from '@/Components/ui';
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

export default function Index({ products, categories, filters, can }: IndexProps) {
    const pagination = toPagination(products);
    const table = useServerTable({
        url: route('admin.products.index'),
        filters: { ...filters, per_page: pagination.per_page },
        only: ['products', 'filters'],
    });
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const [isActive, setIsActive] = useState(filters.is_active ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ search, category_id: categoryId, is_active: isActive });
    };

    const columns: DataTableColumn<Product>[] = [
        {
            key: 'sku',
            header: 'SKU',
            sortable: true,
            cell: (p) => <span className="font-mono text-sm">{p.sku}</span>,
        },
        { key: 'name', header: 'Name', sortable: true },
        { key: 'category', header: 'Category', cell: (p) => p.category?.name ?? '-' },
        {
            key: 'unit',
            header: 'Unit',
            cell: (p) => (p.unit ? `${p.unit.name} (${p.unit.symbol})` : '-'),
        },
        { key: 'sell_price', header: 'Sell Price', cell: (p) => p.sell_price ?? '-' },
        {
            key: 'is_active',
            header: 'Status',
            cell: (p) => (
                <Badge variant={p.is_active ? 'success' : 'danger'}>
                    {p.is_active ? 'Active' : 'Inactive'}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            cell: (p) => (
                <DataTableActions
                    showHref={route('admin.products.show', p.id)}
                    editHref={route('admin.products.edit', p.id)}
                    deleteHref={route('admin.products.destroy', p.id)}
                    deleteMessage={`Delete ${p.name}?`}
                    deleteDescription={`This will permanently remove the product "${p.name}".`}
                />
            ),
        },
    ];

    return (
        <AdminLayout title="Barang">
            <div className="space-y-6">
                <PageHeader
                    title="Barang"
                    subtitle="Manage product master data."
                    actions={
                        <>
                            <ExportMenu
                                exportUrl={route('admin.products.export')}
                                params={table.params}
                                canExport={can.export}
                                formats={['csv', 'pdf']}
                            />
                            {can.create && (
                                <Button
                                    type="button"
                                    variant="success"
                                    onClick={() => router.get(route('admin.products.create'))}
                                >
                                    Create Product
                                </Button>
                            )}
                        </>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input
                                label="Search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="SKU or name"
                            />
                            <NativeSelect
                                label="Category"
                                value={categoryId}
                                onChange={(e) => setCategoryId(e.target.value)}
                            >
                                <option value="">All</option>
                                {categories.data.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                label="Active"
                                value={isActive}
                                onChange={(e) => setIsActive(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="true">Active</option>
                                <option value="false">Inactive</option>
                            </NativeSelect>
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
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
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
