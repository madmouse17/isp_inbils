import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { UtilisationBar } from '@/Components/composite/UtilisationBar';
import {
    EmptyCell,
    EnumBadge,
    Input,
    SearchSelect,
    Tab,
    TabList,
    TabPanel,
    Tabs,
    type SearchSelectOption,
} from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { toPagination } from '@/lib/pagination';
import { ODN_PORT_STATE, SUBSCRIPTION_STATUS } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { OdpRow, PonPortSummary, PopOption, UnmappedSubscription } from '@/types/ipam-odn';

interface IndexProps extends Record<string, unknown> {
    odps: Paginated<OdpRow>;
    ponPorts: Paginated<PonPortSummary>;
    unmapped: Paginated<UnmappedSubscription>;
    filters: { pop_id: string | null; min_percent: string | null; search: string | null };
    pops: PopOption[];
}

type OdnTab = 'odps' | 'pon' | 'unmapped';

const secondPage = (page: Paginated<unknown>) =>
    page.meta.current_page > 1 ? page.meta.current_page : undefined;

export default function Index({ odps, ponPorts, unmapped, filters, pops }: IndexProps) {
    const [tab, setTab] = useState<OdnTab>('odps');
    const { params, set, setMany, processing } = useTableQuery(
        {
            search: filters.search,
            pop_id: filters.pop_id,
            min_percent: filters.min_percent,
            odps_page: secondPage(odps),
            ports_page: secondPage(ponPorts),
            unmapped_page: secondPage(unmapped),
        },
        { only: ['odps', 'ponPorts', 'unmapped', 'filters'] },
    );
    const filtered = Boolean(params.search || params.pop_id || params.min_percent);

    /** A new filter starts every list from its first page. */
    const filter = (key: 'search' | 'pop_id' | 'min_percent', value: string) =>
        setMany({ [key]: value, odps_page: null, ports_page: null, unmapped_page: null });

    const resetFilters = () =>
        setMany({
            search: '',
            pop_id: '',
            min_percent: '',
            odps_page: null,
            ports_page: null,
            unmapped_page: null,
        });

    const popOptions = useMemo<SearchSelectOption[]>(
        () => pops.map((pop) => ({ value: String(pop.id), label: `${pop.code} - ${pop.name}` })),
        [pops],
    );

    const odpColumns: DataTableColumn<OdpRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'ODP',
                cell: (row) => (
                    <Link
                        href={route('admin.odn.assets.show', row.asset_id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            { key: 'name', header: 'Nama', cell: (row) => row.name },
            {
                key: 'location',
                header: 'Lokasi',
                cell: (row) =>
                    row.location_path ? (
                        <span className="block max-w-64 truncate" title={row.location_path}>
                            {row.location_path}
                        </span>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'capacity',
                header: 'Kapasitas',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) =>
                    row.capacity === 0 ? (
                        <EnumBadge map={ODN_PORT_STATE} value="no_splitter" />
                    ) : (
                        row.capacity
                    ),
            },
            {
                key: 'used',
                header: 'Terpakai',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => row.used,
            },
            {
                key: 'free',
                header: 'Kosong',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => (
                    <span className="inline-flex items-center gap-2">
                        {row.free}
                        {row.capacity > 0 && row.free === 0 && (
                            <EnumBadge map={ODN_PORT_STATE} value="full" />
                        )}
                    </span>
                ),
            },
            {
                key: 'percent',
                header: 'Pemakaian',
                cell: (row) => (
                    <UtilisationBar percent={row.percent} label={`${row.code} port terpakai`} />
                ),
            },
        ],
        [],
    );

    const ponColumns: DataTableColumn<PonPortSummary>[] = useMemo(
        () => [
            {
                key: 'olt',
                header: 'OLT',
                cell: (row) => (
                    <Link
                        href={route('admin.odn.assets.show', row.olt_asset_id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.olt_code}
                    </Link>
                ),
            },
            {
                key: 'port',
                header: 'Port PON',
                cell: (row) => `PON ${row.number}${row.label ? ` · ${row.label}` : ''}`,
            },
            {
                key: 'subscribers',
                header: 'Pelanggan',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => row.subscribers,
            },
        ],
        [],
    );

    const unmappedColumns: DataTableColumn<UnmappedSubscription>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Langganan',
                cell: (row) => (
                    <Link
                        href={route('admin.subscriptions.show', row.subscription_id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                key: 'customer',
                header: 'Pelanggan',
                cell: (row) => row.customer_name ?? <EmptyCell />,
            },
            {
                key: 'pop',
                header: 'POP layanan',
                cell: (row) => row.serving_pop_code ?? <EmptyCell />,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={SUBSCRIPTION_STATUS} value={row.status} />,
            },
        ],
        [],
    );

    return (
        <AdminLayout title="ODN">
            <div className="space-y-6">
                <PageHeader
                    title="ODN"
                    description="Seberapa penuh tiap ODP, siapa di belakang tiap port PON, dan langganan aktif yang belum punya port drop tercatat."
                />

                <div className="grid items-end gap-3 rounded-lg border bg-card p-3 sm:grid-cols-3">
                    <Input
                        id="filter-search"
                        label="Cari"
                        value={params.search ?? ''}
                        onChange={(event) => filter('search', event.target.value)}
                        placeholder="Kode, nama, atau pelanggan"
                    />
                    <SearchSelect
                        id="filter-pop"
                        label="POP"
                        value={params.pop_id ?? ''}
                        onChange={(value) => filter('pop_id', value)}
                        options={popOptions}
                        placeholder="Semua POP"
                        emptyText="Tidak ada POP yang cocok."
                    />
                    <Input
                        id="filter-min-percent"
                        label="ODP minimal terpakai (%)"
                        type="number"
                        min={0}
                        max={100}
                        value={params.min_percent ?? ''}
                        onChange={(event) => filter('min_percent', event.target.value)}
                    />
                </div>

                <Tabs value={tab} onValueChange={(value) => setTab(value as OdnTab)}>
                    <TabList aria-label="Tampilan ODN" className="overflow-x-auto">
                        <Tab value="odps">ODP ({odps.meta.total})</Tab>
                        <Tab value="pon">Port PON ({ponPorts.meta.total})</Tab>
                        <Tab value="unmapped">
                            Langganan tanpa port drop ({unmapped.meta.total})
                        </Tab>
                    </TabList>

                    <TabPanel value="odps">
                        <DataTable
                            columns={odpColumns}
                            data={odps.data}
                            pagination={toPagination(odps)}
                            onPageChange={(page) => set('odps_page', page)}
                            loading={processing}
                            emptyTitle="Belum ada ODP"
                            emptyDescription="ODP adalah aset jaringan bertipe ODP. Daftarkan satu, lalu tambahkan splitter agar punya port."
                            hasFilters={filtered}
                            onResetFilters={resetFilters}
                            itemLabel="ODP"
                        />
                    </TabPanel>
                    <TabPanel value="pon">
                        <DataTable
                            columns={ponColumns}
                            data={ponPorts.data}
                            pagination={toPagination(ponPorts)}
                            onPageChange={(page) => set('ports_page', page)}
                            loading={processing}
                            emptyTitle="Belum ada port PON"
                            emptyDescription="Buka OLT di register ODN dan tambahkan port PON-nya."
                            hasFilters={filtered}
                            onResetFilters={resetFilters}
                            itemLabel="port PON"
                        />
                    </TabPanel>
                    <TabPanel value="unmapped">
                        <DataTable
                            columns={unmappedColumns}
                            data={unmapped.data}
                            pagination={toPagination(unmapped)}
                            onPageChange={(page) => set('unmapped_page', page)}
                            loading={processing}
                            emptyTitle="Semua langganan aktif punya port drop"
                            emptyDescription="Langganan tanpa port drop tercatat muncul di sini agar bisa diisi."
                            hasFilters={filtered}
                            onResetFilters={resetFilters}
                            itemLabel="langganan"
                        />
                    </TabPanel>
                </Tabs>
            </div>
        </AdminLayout>
    );
}
