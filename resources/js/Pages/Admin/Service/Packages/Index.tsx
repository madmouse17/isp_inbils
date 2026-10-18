import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTableActions, NativeSelect, PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { EmptyCell } from '@/Components/ui/EmptyCell';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import { Input } from '@/Components/ui/Input';
import { SearchSelect } from '@/Components/ui/SearchSelect';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate, formatMoney } from '@/lib/format';
import { ACTIVE_STATE } from '@/lib/statuses';

type Row = {
    id: string;
    name: string;
    code: string;
    price_mrc: number | string;
    price_otc: number | string;
    is_active?: boolean;
    category: { id: string; code: string; name: string } | null;
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
    slaTiers: { data: { id: string; name: string }[] };
    packageCategories: { id: string; code: string; name: string; is_active: boolean }[];
    filters: {
        search?: string;
        is_active?: string;
        sla_tier_id?: string;
        service_package_category_id?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
};

const NO_FILTERS = { search: '', is_active: '', sla_tier_id: '', service_package_category_id: '' };

const ACTIVE_OPTIONS = [
    { value: '', label: 'Semua status' },
    { value: '1', label: 'Aktif' },
    { value: '0', label: 'Nonaktif' },
];

export default function PackagesIndex({
    servicePackages,
    slaTiers,
    packageCategories,
    filters,
    can,
}: Props) {
    const { params, search, setSearch, set, setMany, sortBy, sortDir, onSort, processing } =
        useServerTable({
            url: route('admin.service-packages.index'),
            filters,
            only: ['servicePackages', 'filters', 'can', 'slaTiers', 'packageCategories'],
        });

    const slaOptions = useMemo(
        () => slaTiers.data.map((tier) => ({ value: String(tier.id), label: tier.name })),
        [slaTiers],
    );
    const categoryOptions = useMemo(
        () =>
            packageCategories.map((category) => ({
                value: String(category.id),
                label: category.is_active ? category.name : `${category.name} (nonaktif)`,
            })),
        [packageCategories],
    );

    const hasFilters = Object.keys(NO_FILTERS).some((key) => params[key]);

    const columns: DataTableColumn<Row>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (row) => (
                    <Link
                        href={route('admin.service-packages.show', row.id)}
                        className="font-medium text-primary-text hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <span className="font-mono text-sm whitespace-nowrap">{row.code}</span>
                ),
            },
            {
                key: 'category',
                header: 'Kategori',
                cell: (row) => row.category?.name ?? <EmptyCell />,
            },
            {
                key: 'price_mrc',
                header: 'Harga bulanan',
                sortable: true,
                sortKey: 'price_mrc',
                headerClassName: 'text-right',
                className: 'text-right whitespace-nowrap tabular-nums',
                cell: (row) => formatMoney(row.price_mrc),
            },
            {
                key: 'price_otc',
                header: 'Harga pemasangan',
                sortable: true,
                sortKey: 'price_otc',
                headerClassName: 'text-right',
                className: 'text-right whitespace-nowrap tabular-nums',
                cell: (row) => formatMoney(row.price_otc),
            },
            {
                key: 'is_active',
                header: 'Status',
                sortable: true,
                sortKey: 'is_active',
                cell: (row) => <EnumBadge map={ACTIVE_STATE} value={Boolean(row.is_active)} />,
            },
            {
                key: 'created_at',
                header: 'Dibuat',
                sortable: true,
                sortKey: 'created_at',
                className: 'whitespace-nowrap',
                cell: (row) => formatDate(row.created_at),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <DataTableActions
                        showHref={route('admin.service-packages.show', row.id)}
                        editHref={route('admin.service-packages.edit', row.id)}
                        deleteHref={route('admin.service-packages.destroy', row.id)}
                        deleteMessage={`Hapus ${row.name}?`}
                        deleteDescription={`Paket "${row.name}" akan dihapus permanen. Paket yang masih dipakai langganan menunggu atau aktif tidak bisa dihapus.`}
                    />
                ),
            },
        ],
        [],
    );

    const createButton = can.create ? (
        <Button asChild variant="primary" size="sm">
            <Link href={route('admin.service-packages.create')}>Buat Paket Layanan</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Paket Layanan">
            <div className="space-y-4">
                <PageHeader
                    title="Paket Layanan"
                    subtitle="Paket yang dijual ke pelanggan, lengkap dengan harga, profil kecepatan, dan tingkat SLA."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.service-packages.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {createButton}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Input
                            id="pkg-search"
                            label="Cari"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Nama atau kode"
                        />
                    </div>
                    <NativeSelect
                        id="pkg-active"
                        label="Status"
                        value={params.is_active ?? ''}
                        onChange={(e) => set('is_active', e.target.value)}
                        options={ACTIVE_OPTIONS}
                        wrapperClassName="sm:w-40"
                    />
                    <SearchSelect
                        id="pkg-sla"
                        label="Tingkat SLA"
                        value={params.sla_tier_id ?? ''}
                        onChange={(value) => set('sla_tier_id', value)}
                        options={slaOptions}
                        placeholder="Semua tingkat"
                        className="sm:w-52"
                    />
                    <SearchSelect
                        id="pkg-category"
                        label="Kategori"
                        value={params.service_package_category_id ?? ''}
                        onChange={(value) => set('service_package_category_id', value)}
                        options={categoryOptions}
                        placeholder="Semua kategori"
                        className="sm:w-52"
                    />
                    {hasFilters ? (
                        <Button type="button" variant="outline" onClick={() => setMany(NO_FILTERS)}>
                            Reset filter
                        </Button>
                    ) : null}
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(servicePackages)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    loading={processing}
                    itemLabel="paket"
                    hasFilters={hasFilters}
                    onResetFilters={() => setMany(NO_FILTERS)}
                    emptyTitle="Belum ada paket layanan"
                    emptyDescription="Paket layanan dipilih saat membuat langganan, dan harganya disalin ke langganan itu."
                    emptyAction={createButton}
                />
            </div>
        </AdminLayout>
    );
}
