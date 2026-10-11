import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { UtilisationBar } from '@/Components/composite/UtilisationBar';
import { Badge, Input, NativeSelect, Tab, TabList, TabPanel, Tabs } from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { toPagination } from '@/lib/pagination';
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
            { key: 'name', header: 'Name', cell: (row) => row.name },
            {
                key: 'location',
                header: 'Location',
                cell: (row) =>
                    row.location_path ? (
                        <span className="block max-w-64 truncate" title={row.location_path}>
                            {row.location_path}
                        </span>
                    ) : (
                        '—'
                    ),
            },
            {
                key: 'capacity',
                header: 'Capacity',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) =>
                    row.capacity === 0 ? (
                        <Badge variant="secondary">No splitter</Badge>
                    ) : (
                        row.capacity
                    ),
            },
            {
                key: 'used',
                header: 'Used',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => row.used,
            },
            {
                key: 'free',
                header: 'Free',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => (
                    <span className="inline-flex items-center gap-2">
                        {row.free}
                        {row.capacity > 0 && row.free === 0 && (
                            <Badge variant="outline">Full</Badge>
                        )}
                    </span>
                ),
            },
            {
                key: 'percent',
                header: 'Utilisation',
                cell: (row) => (
                    <UtilisationBar percent={row.percent} label={`${row.code} ports in use`} />
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
                header: 'PON port',
                cell: (row) => `PON ${row.number}${row.label ? ` · ${row.label}` : ''}`,
            },
            {
                key: 'subscribers',
                header: 'Subscribers',
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
                header: 'Subscription',
                cell: (row) => (
                    <Link
                        href={route('admin.subscriptions.show', row.subscription_id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            { key: 'customer', header: 'Customer', cell: (row) => row.customer_name ?? '—' },
            { key: 'pop', header: 'Serving POP', cell: (row) => row.serving_pop_code ?? '—' },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <Badge variant="success">{row.status}</Badge>,
            },
        ],
        [],
    );

    return (
        <AdminLayout title="ODN">
            <div className="space-y-6">
                <PageHeader
                    title="ODN"
                    description="How full each ODP is, who sits behind each PON port, and which active subscriptions have no recorded drop port."
                />

                <div className="grid items-end gap-3 rounded-lg border bg-card p-3 sm:grid-cols-3">
                    <Input
                        id="filter-search"
                        label="Search"
                        value={params.search ?? ''}
                        onChange={(event) => filter('search', event.target.value)}
                        placeholder="Code, name or customer"
                    />
                    <NativeSelect
                        id="filter-pop"
                        label="POP"
                        value={params.pop_id ?? ''}
                        onChange={(event) => filter('pop_id', event.target.value)}
                        options={[
                            { value: '', label: 'All POPs' },
                            ...pops.map((pop) => ({
                                value: String(pop.id),
                                label: `${pop.code} — ${pop.name}`,
                            })),
                        ]}
                    />
                    <Input
                        id="filter-min-percent"
                        label="ODP minimum used (%)"
                        type="number"
                        min={0}
                        max={100}
                        value={params.min_percent ?? ''}
                        onChange={(event) => filter('min_percent', event.target.value)}
                    />
                </div>

                <Tabs value={tab} onValueChange={(value) => setTab(value as OdnTab)}>
                    <TabList aria-label="ODN views" className="overflow-x-auto">
                        <Tab value="odps">ODPs ({odps.meta.total})</Tab>
                        <Tab value="pon">PON ports ({ponPorts.meta.total})</Tab>
                        <Tab value="unmapped">Unmapped subscriptions ({unmapped.meta.total})</Tab>
                    </TabList>

                    <TabPanel value="odps">
                        <DataTable
                            columns={odpColumns}
                            data={odps.data}
                            pagination={toPagination(odps)}
                            onPageChange={(page) => set('odps_page', page)}
                            loading={processing}
                            emptyTitle={filtered ? 'No ODP matches the filters' : 'No ODPs yet'}
                            emptyDescription={
                                filtered
                                    ? 'Clear a filter to see the rest.'
                                    : 'ODPs are network assets of type ODP. Register one, then add a splitter to give it ports.'
                            }
                        />
                    </TabPanel>
                    <TabPanel value="pon">
                        <DataTable
                            columns={ponColumns}
                            data={ponPorts.data}
                            pagination={toPagination(ponPorts)}
                            onPageChange={(page) => set('ports_page', page)}
                            loading={processing}
                            emptyTitle={
                                filtered ? 'No PON port matches the filters' : 'No PON ports yet'
                            }
                            emptyDescription={
                                filtered
                                    ? 'Clear a filter to see the rest.'
                                    : 'Open an OLT in the ODN register and add its PON ports.'
                            }
                        />
                    </TabPanel>
                    <TabPanel value="unmapped">
                        <DataTable
                            columns={unmappedColumns}
                            data={unmapped.data}
                            pagination={toPagination(unmapped)}
                            onPageChange={(page) => set('unmapped_page', page)}
                            loading={processing}
                            emptyTitle={
                                filtered
                                    ? 'No subscription matches the filters'
                                    : 'Every active subscription has a drop port'
                            }
                            emptyDescription={
                                filtered
                                    ? 'Clear a filter to see the rest.'
                                    : 'Subscriptions without a recorded port appear here so you can backfill them.'
                            }
                        />
                    </TabPanel>
                </Tabs>
            </div>
        </AdminLayout>
    );
}
