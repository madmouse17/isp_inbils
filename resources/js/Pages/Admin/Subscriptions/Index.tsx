import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { NativeSelect, PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { DataTableActions } from '@/Components/composite/DataTableActions';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import { Input } from '@/Components/ui/Input';
import { Modal } from '@/Components/ui/Modal';
import { SearchSelect } from '@/Components/ui/SearchSelect';
import { useServerTable } from '@/hooks/useServerTable';
import { formatMoney } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import { SUBSCRIPTION_STATUS, optionsOf } from '@/lib/statuses';
import type { ServiceSubscription } from '@/types/models';
import { packageGroupOptions, type PackageGroup } from '@/lib/packageGroups';

interface SubIndexProps {
    customer: { id: string; code: string; name: string };
    subscriptions: {
        data: ServiceSubscription[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
    };
    /** Active packages by category, ordered for this customer's type. */
    packageGroups: PackageGroup[];
    addresses: { data: { id: string; label: string; address: string; is_primary: boolean }[] };
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

const STATUS_OPTIONS = [{ value: '', label: 'Semua status' }, ...optionsOf(SUBSCRIPTION_STATUS)];

export default function Index({
    customer,
    subscriptions,
    packageGroups,
    addresses,
    filters,
    can,
}: SubIndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const packageOptions = useMemo(() => packageGroupOptions(packageGroups), [packageGroups]);
    const addressOptions = useMemo(
        () =>
            addresses.data.map((address) => ({
                value: String(address.id),
                label: address.label,
                description: address.address,
            })),
        [addresses.data],
    );
    const primaryAddressId = addresses.data.find((address) => address.is_primary)?.id;
    const { data, setData, post, processing, errors, reset } = useForm({
        service_package_id: '',
        // The customer's primary address, still changeable in the form.
        installation_address_id: primaryAddressId === undefined ? '' : String(primaryAddressId),
        billing_day: '1',
        mrc_amount: '',
        otc_installation_fee: '0',
        contract_months: '',
        notes: '',
    });

    const {
        params,
        search,
        setSearch,
        set,
        setMany,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.customers.subscriptions.index', customer.id),
        filters,
        only: ['subscriptions', 'filters', 'can', 'packageGroups', 'addresses'],
    });

    const hasFilters = Boolean(params.search || params.service_package_id || params.status);

    const columns: DataTableColumn<ServiceSubscription>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <Link
                        href={route('admin.subscriptions.show', row.id)}
                        className="font-mono text-sm font-medium whitespace-nowrap text-primary-text hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                key: 'package',
                header: 'Paket',
                cell: (row) => row.package?.name ?? `#${row.service_package_id}`,
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => <EnumBadge map={SUBSCRIPTION_STATUS} value={row.status} />,
            },
            {
                key: 'mrc_amount',
                header: 'Biaya bulanan',
                className: 'whitespace-nowrap',
                cell: (row) => formatMoney(row.mrc_amount),
            },
            { key: 'billing_day', header: 'Tanggal tagihan', cell: (row) => row.billing_day },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <DataTableActions showHref={route('admin.subscriptions.show', row.id)} />
                ),
            },
        ],
        [],
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.customers.subscriptions.store', customer.id), {
            onSuccess: () => {
                reset();
                setModalOpen(false);
            },
        });
    };

    const createButton = (
        <Button type="button" variant="primary" size="sm" onClick={() => setModalOpen(true)}>
            Buat Langganan
        </Button>
    );

    return (
        <AdminLayout title="Langganan">
            <div className="space-y-6">
                <PageHeader
                    title="Langganan"
                    subtitle={`${customer.code} - ${customer.name}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route(
                                        'admin.customers.subscriptions.export',
                                        customer.id,
                                    )}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.customers.show', customer.id)}>
                                    Kembali
                                </Link>
                            </Button>
                            {createButton}
                        </div>
                    }
                />

                <div className="space-y-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Input
                                id="sub-search"
                                label="Cari"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Kode atau nama paket"
                            />
                        </div>
                        <SearchSelect
                            id="sub-filter-package"
                            label="Paket"
                            value={params.service_package_id ?? ''}
                            onChange={(value) => set('service_package_id', value)}
                            options={packageOptions}
                            placeholder="Semua paket"
                            className="sm:w-60"
                        />
                        <NativeSelect
                            id="sub-filter-status"
                            label="Status"
                            value={params.status ?? ''}
                            onChange={(e) => set('status', e.target.value)}
                            options={STATUS_OPTIONS}
                            wrapperClassName="sm:w-44"
                        />
                        {hasFilters ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    setMany({ search: '', service_package_id: '', status: '' })
                                }
                            >
                                Reset filter
                            </Button>
                        ) : null}
                    </div>

                    <DataTable
                        columns={columns}
                        pagination={toPagination(subscriptions)}
                        sortBy={sortBy}
                        sortDir={sortDir}
                        onSort={onSort}
                        onPageChange={(page) => set('page', page)}
                        onPerPageChange={(perPage) => set('per_page', perPage)}
                        loading={tableProcessing}
                        itemLabel="langganan"
                        hasFilters={hasFilters}
                        onResetFilters={() =>
                            setMany({ search: '', service_package_id: '', status: '' })
                        }
                        emptyTitle="Belum ada langganan"
                        emptyDescription="Buat langganan pertama untuk pelanggan ini. Langganan berstatus Menunggu sampai diaktifkan."
                        emptyAction={createButton}
                    />
                </div>

                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Buat Langganan">
                    <form onSubmit={submit} className="space-y-4">
                        <SearchSelect
                            id="subscription-package"
                            label="Paket layanan"
                            value={data.service_package_id}
                            onChange={(value) => setData('service_package_id', value)}
                            options={packageOptions}
                            placeholder="Pilih paket"
                            error={errors.service_package_id}
                            required
                        />
                        <SearchSelect
                            id="subscription-address"
                            label="Alamat pemasangan"
                            value={data.installation_address_id}
                            onChange={(value) => setData('installation_address_id', value)}
                            options={addressOptions}
                            placeholder="Pilih alamat"
                            emptyText="Pelanggan ini belum punya alamat."
                            error={errors.installation_address_id}
                            required
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Tanggal tagihan (1-28)"
                                type="number"
                                min={1}
                                max={28}
                                value={data.billing_day}
                                onChange={(e) => setData('billing_day', e.target.value)}
                                error={errors.billing_day}
                                required
                            />
                            <Input
                                label="Biaya bulanan"
                                value={data.mrc_amount}
                                onChange={(e) => setData('mrc_amount', e.target.value)}
                                error={errors.mrc_amount}
                                placeholder="Otomatis dari paket"
                            />
                            <Input
                                label="Biaya pemasangan"
                                value={data.otc_installation_fee}
                                onChange={(e) => setData('otc_installation_fee', e.target.value)}
                                error={errors.otc_installation_fee}
                            />
                            <Input
                                label="Masa kontrak (bulan)"
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
                                Batal
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                Buat
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
