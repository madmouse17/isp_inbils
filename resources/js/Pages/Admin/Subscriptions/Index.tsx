import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, StatusBadge } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Modal } from '@/Components/ui/Modal';
import { NativeSelect } from '@/Components/composite';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { ServiceSubscription } from '@/types/models';

interface SubIndexProps {
    customer: { id: number; code: string; name: string };
    subscriptions: {
        data: ServiceSubscription[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
    };
    packages: { data: { id: number; code: string; name: string; price_mrc: string }[] };
    addresses: { data: { id: number; label: string; address: string }[] };
    filters: {
        search?: string;
        service_package_id?: string;
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { export: boolean };
}

export default function Index({
    customer,
    subscriptions,
    packages,
    addresses,
    filters,
    can,
}: SubIndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        service_package_id: '',
        installation_address_id: '',
        billing_day: '1',
        mrc_amount: '',
        otc_installation_fee: '0',
        contract_months: '',
        notes: '',
    });

    const { params, set, sortBy, sortDir, onSort } = useServerTable({
        url: route('admin.customers.subscriptions.index', customer.id),
        filters,
        only: ['subscriptions', 'filters', 'can', 'packages', 'addresses'],
    });

    const columns: DataTableColumn<ServiceSubscription>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (row) => <span className="font-mono text-sm">{row.code}</span>,
            },
            {
                key: 'package',
                header: 'Package',
                cell: (row) => row.package?.name ?? `#${row.service_package_id}`,
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => (
                    <StatusBadge
                        variant={
                            row.status === 'active'
                                ? 'success'
                                : row.status === 'suspended'
                                  ? 'warning'
                                  : row.status === 'terminated'
                                    ? 'danger'
                                    : 'muted'
                        }
                    >
                        {row.status}
                    </StatusBadge>
                ),
            },
            { key: 'mrc_amount', header: 'MRC', cell: (row) => row.mrc_amount },
            { key: 'billing_day', header: 'Billing Day', cell: (row) => row.billing_day },
            {
                key: 'actions',
                header: 'Actions',
                cell: (row) => (
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={route('admin.subscriptions.show', row.id)}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Show
                        </Link>
                    </div>
                ),
            },
        ],
        [],
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.customers.subscriptions.store', customer.id), {
            onSuccess: () => setModalOpen(false),
        });
    };

    return (
        <AdminLayout title="Subscriptions">
            <div className="space-y-6">
                <PageHeader
                    title="Subscriptions"
                    subtitle={`${customer.code} — ${customer.name}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route(
                                        'admin.customers.subscriptions.export',
                                        customer.id,
                                    )}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.get(route('admin.customers.show', customer.id))
                                }
                            >
                                Back
                            </Button>
                            <Button type="button" onClick={() => setModalOpen(true)}>
                                Create Subscription
                            </Button>
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="sub-search"
                        >
                            Search
                        </label>
                        <Input
                            id="sub-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Code or package…"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Package</label>
                        <NativeSelect
                            value={params.service_package_id || '__all__'}
                            onChange={(e) =>
                                set(
                                    'service_package_id',
                                    e.target.value === '__all__' ? '' : e.target.value,
                                )
                            }
                            options={[
                                { value: '__all__', label: 'All' },
                                ...packages.data.map((pkg) => ({
                                    value: String(pkg.id),
                                    label: `${pkg.name} (${pkg.code})`,
                                })),
                            ]}
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Status</label>
                        <NativeSelect
                            value={params.status || '__all__'}
                            onChange={(e) =>
                                set('status', e.target.value === '__all__' ? '' : e.target.value)
                            }
                            options={[
                                { value: '__all__', label: 'All' },
                                { value: 'active', label: 'Active' },
                                { value: 'suspended', label: 'Suspended' },
                                { value: 'pending', label: 'Pending' },
                                { value: 'terminated', label: 'Terminated' },
                            ]}
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(subscriptions)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    emptyTitle="No subscriptions"
                    emptyDescription="No subscriptions match the current filters."
                />

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title="Create Subscription"
                >
                    <form onSubmit={submit} className="space-y-4">
                        <NativeSelect
                            label="Service Package"
                            value={data.service_package_id}
                            onChange={(e) => setData('service_package_id', e.target.value)}
                            error={errors.service_package_id}
                            options={[
                                { value: '', label: 'Select package…' },
                                ...packages.data.map((pkg) => ({
                                    value: String(pkg.id),
                                    label: `${pkg.name} (MRC: ${pkg.price_mrc})`,
                                })),
                            ]}
                            required
                        />
                        <NativeSelect
                            label="Installation Address"
                            value={data.installation_address_id}
                            onChange={(e) => setData('installation_address_id', e.target.value)}
                            error={errors.installation_address_id}
                            options={[
                                { value: '', label: 'Select address…' },
                                ...addresses.data.map((a) => ({
                                    value: String(a.id),
                                    label: `${a.label} — ${a.address}`,
                                })),
                            ]}
                            required
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Billing Day (1-28)"
                                type="number"
                                min={1}
                                max={28}
                                value={data.billing_day}
                                onChange={(e) => setData('billing_day', e.target.value)}
                                error={errors.billing_day}
                                required
                            />
                            <Input
                                label="MRC Amount"
                                value={data.mrc_amount}
                                onChange={(e) => setData('mrc_amount', e.target.value)}
                                error={errors.mrc_amount}
                                placeholder="Auto from package"
                            />
                            <Input
                                label="OTC Installation Fee"
                                value={data.otc_installation_fee}
                                onChange={(e) => setData('otc_installation_fee', e.target.value)}
                                error={errors.otc_installation_fee}
                            />
                            <Input
                                label="Contract Months"
                                type="number"
                                value={data.contract_months}
                                onChange={(e) => setData('contract_months', e.target.value)}
                                error={errors.contract_months}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setModalOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={processing}>
                                Create
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
