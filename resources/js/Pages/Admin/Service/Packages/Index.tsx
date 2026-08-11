import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate, formatMoney } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    code: string;
    price: number | string;
    currency?: string;
    billing_cycle?: string;
    is_active?: boolean;
    created_at: string | null;
};

type Props = {
    servicePackages: {
        data: Row[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    slaTiers: { data: { id: number; name: string }[] };
    filters: {
        search?: string;
        is_active?: string;
        sla_tier_id?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
};

export default function PackagesIndex({ servicePackages, slaTiers, filters, can }: Props) {
    const { params, set, sortBy, sortDir, onSort } = useServerTable({
        url: route('admin.service-packages.index'),
        filters,
        only: ['servicePackages', 'filters', 'can', 'slaTiers'],
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
                        href={route('admin.service-packages.edit', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (row) => row.code,
            },
            {
                key: 'price',
                header: 'Price',
                sortable: true,
                sortKey: 'price',
                className: 'text-right tabular-nums',
                cell: (row) => formatMoney(row.price, row.currency ?? 'IDR'),
            },
            {
                key: 'billing_cycle',
                header: 'Cycle',
                sortable: true,
                sortKey: 'billing_cycle',
                cell: (row) => row.billing_cycle ?? '—',
            },
            {
                key: 'is_active',
                header: 'Active',
                sortable: true,
                sortKey: 'is_active',
                cell: (row) => <StatusBadge status={row.is_active ? 'active' : 'inactive'} />,
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
                            href={route('admin.service-packages.show', row.id)}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Show
                        </Link>
                        <Link
                            href={route('admin.service-packages.edit', row.id)}
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
                                    router.delete(route('admin.service-packages.destroy', row.id));
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
            <Head title="Packages" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Packages"
                    description="Service packages sold to customers."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.service-packages.export')}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button asChild size="sm">
                                    <Link href={route('admin.service-packages.create')}>
                                        <Plus className="size-4" />
                                        New package
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="pkg-search"
                        >
                            Search
                        </label>
                        <Input
                            id="pkg-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Name or code…"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Active</label>
                        <Select
                            value={params.is_active || '__all__'}
                            onValueChange={(value) =>
                                set('is_active', value === '__all__' ? '' : value)
                            }
                        >
                            <SelectTrigger className="w-[8rem]">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                <SelectItem value="1">Active</SelectItem>
                                <SelectItem value="0">Inactive</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">
                            SLA Tier
                        </label>
                        <Select
                            value={params.sla_tier_id || '__all__'}
                            onValueChange={(value) =>
                                set('sla_tier_id', value === '__all__' ? '' : value)
                            }
                        >
                            <SelectTrigger className="w-[12rem]">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {slaTiers.data.map((tier) => (
                                    <SelectItem key={tier.id} value={String(tier.id)}>
                                        {tier.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(servicePackages)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    emptyTitle="No packages"
                    emptyDescription="No packages match the current filters."
                    loading={false}
                />
            </div>
        </AppLayout>
    );
}
