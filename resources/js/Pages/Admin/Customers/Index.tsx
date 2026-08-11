import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ExportMenu';
import { Badge, Button, Card, CardContent, Input, NativeSelect } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';

interface CustomerRow {
    id: number;
    code: string;
    name: string;
    type: string;
    phone?: string | null;
    is_active: boolean;
    addresses_count?: number;
    subscriptions_count?: number;
}

interface IndexProps {
    customers: Paginated<CustomerRow>;
    filters: {
        type?: string;
        status?: string;
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean; update: boolean; delete: boolean };
}

export default function Index({ customers, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const { params, visit, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.customers.index'),
        filters,
        only: ['customers', 'filters', 'can'],
    });

    const columns: DataTableColumn<CustomerRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (customer) => <span className="font-mono text-sm">{customer.code}</span>,
            },
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
                cell: (customer) => <span className="font-medium">{customer.name}</span>,
            },
            {
                key: 'type',
                header: 'Type',
                sortable: true,
                sortKey: 'type',
                cell: (customer) => (
                    <Badge variant={customer.type === 'Company' ? 'brand' : 'neutral'}>
                        {customer.type}
                    </Badge>
                ),
            },
            {
                key: 'phone',
                header: 'Phone',
                sortable: true,
                sortKey: 'phone',
                cell: (customer) => customer.phone ?? '-',
            },
            {
                key: 'is_active',
                header: 'Status',
                sortable: true,
                sortKey: 'is_active',
                cell: (customer) => (
                    <Badge variant={customer.is_active ? 'success' : 'danger'}>
                        {customer.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                ),
            },
            {
                key: 'addresses_count',
                header: 'Addresses',
                cell: (customer) => customer.addresses_count ?? 0,
            },
            {
                key: 'subscriptions_count',
                header: 'Subscriptions',
                cell: (customer) => customer.subscriptions_count ?? 0,
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (customer) => (
                    <DataTableActions
                        showHref={route('admin.customers.show', customer.id)}
                        editHref={can.update ? route('admin.customers.edit', customer.id) : undefined}
                        deleteHref={
                            can.delete ? route('admin.customers.destroy', customer.id) : undefined
                        }
                        deleteMessage={`Delete ${customer.name}?`}
                    />
                ),
            },
        ],
        [can.delete, can.update],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit({ search, type, status });
    };

    return (
        <AdminLayout title="Customers">
            <div className="space-y-6">
                <PageHeader
                    title="Customers"
                    subtitle="Manage customer master data."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.customers.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button
                                    type="button"
                                    variant="success"
                                    onClick={() => router.get(route('admin.customers.create'))}
                                >
                                    Create Customer
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form
                            onSubmit={submit}
                            className="grid gap-3 rounded-lg border bg-card p-3 md:grid-cols-[minmax(12rem,1fr)_12rem_12rem_auto] md:items-end"
                        >
                            <Input
                                label="Search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Name, code, phone"
                            />
                            <NativeSelect
                                label="Type"
                                value={type}
                                onChange={(event) => setType(event.target.value)}
                                options={[
                                    { value: '', label: 'All types' },
                                    { value: 'Individual', label: 'Individual' },
                                    { value: 'Company', label: 'Company' },
                                ]}
                            />
                            <NativeSelect
                                label="Status"
                                value={status}
                                onChange={(event) => setStatus(event.target.value)}
                                options={[
                                    { value: '', label: 'All statuses' },
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                            />
                            <Button type="submit" variant="secondary">Filter</Button>
                        </form>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(customers)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
