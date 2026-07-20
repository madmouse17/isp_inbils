import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Pagination,
    Select,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import type { Category, Product, Unit } from '@/types/inventory';

interface IndexProps extends Record<string, unknown> {
    products: {
        data: Product[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    categories: { data: Category[] };
    units: { data: Unit[] };
    filters: { category_id?: string; is_active?: string; search?: string };
    can: { create: boolean };
}

export default function Index({ products, categories, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const [isActive, setIsActive] = useState(filters.is_active ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.products.index'),
            { search, category_id: categoryId, is_active: isActive },
            { preserveState: true },
        );
    };

    const remove = (p: Product) => {
        if (window.confirm(`Delete ${p.name}?`))
            router.delete(route('admin.products.destroy', p.id));
    };

    return (
        <AdminLayout title="Products">
            <div className="space-y-6">
                <PageHeader
                    title="Products"
                    subtitle="Manage product master data."
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.get(route('admin.products.export'))}
                            >
                                Export
                            </Button>
                            {can.create && (
                                <Button
                                    type="button"
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
                            <Select
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
                            </Select>
                            <Select
                                label="Active"
                                value={isActive}
                                onChange={(e) => setIsActive(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="true">Active</option>
                                <option value="false">Inactive</option>
                            </Select>
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>SKU</TH>
                                    <TH>Name</TH>
                                    <TH>Category</TH>
                                    <TH>Unit</TH>
                                    <TH>Sell Price</TH>
                                    <TH>Status</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {products.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    products.data.map((p) => (
                                        <TR key={p.id}>
                                            <TD className="font-mono text-sm">{p.sku}</TD>
                                            <TD>{p.name}</TD>
                                            <TD>{p.category?.name ?? '-'}</TD>
                                            <TD>{p.unit?.symbol ?? '-'}</TD>
                                            <TD>{p.sell_price ?? '-'}</TD>
                                            <TD>
                                                <Badge variant={p.is_active ? 'success' : 'danger'}>
                                                    {p.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TD>
                                            <TD>
                                                <div className="flex flex-wrap gap-2">
                                                    <Link
                                                        href={route('admin.products.show', p.id)}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Show
                                                    </Link>
                                                    <Link
                                                        href={route('admin.products.edit', p.id)}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Edit
                                                    </Link>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(p)}
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={products.current_page}
                            lastPage={products.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.products.index'), { page })
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
