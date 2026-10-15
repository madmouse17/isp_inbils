import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { PrefixDialog } from '@/Components/composite/ipam/PrefixDialog';
import { PrefixUtilisation } from '@/Components/composite/ipam/PrefixUtilisation';
import {
    Button,
    EmptyCell,
    EnumBadge,
    Input,
    NativeSelect,
    SearchSelect,
    type SearchSelectOption,
} from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatCount } from '@/lib/ipam';
import { IP_PREFIX_USAGE, IP_VERSION, optionsOf } from '@/lib/statuses';
import type { PopOption, PrefixRow, VlanOption } from '@/types/ipam-odn';

interface IndexProps extends Record<string, unknown> {
    prefixes: PrefixRow[];
    filters: {
        family: string | null;
        usage: string | null;
        location_id: string | null;
        vlan_id: string | null;
        search: string | null;
    };
    locations: PopOption[];
    vlans: VlanOption[];
    can: { manage: boolean };
}

const familyOptions = [{ value: '', label: 'IPv4 dan IPv6' }, ...optionsOf(IP_VERSION)];
const usageOptions = [{ value: '', label: 'Semua peruntukan' }, ...optionsOf(IP_PREFIX_USAGE)];

export default function Index({ prefixes, filters, locations, vlans, can }: IndexProps) {
    const [creating, setCreating] = useState(false);
    const { params, set, setMany, processing } = useTableQuery(
        {
            search: filters.search,
            family: filters.family,
            usage: filters.usage,
            location_id: filters.location_id,
            vlan_id: filters.vlan_id,
        },
        { only: ['prefixes', 'filters'] },
    );
    const filtered = Object.values(params).some((value) => value !== '');

    const popOptions = useMemo<SearchSelectOption[]>(
        () =>
            locations.map((pop) => ({ value: String(pop.id), label: `${pop.code} - ${pop.name}` })),
        [locations],
    );
    const vlanOptions = useMemo<SearchSelectOption[]>(
        () =>
            vlans.map((vlan) => ({
                value: String(vlan.id),
                label: `VLAN ${vlan.vid} - ${vlan.name}`,
                description: vlan.location_code ?? 'Seluruh perusahaan',
            })),
        [vlans],
    );

    const columns: DataTableColumn<PrefixRow>[] = useMemo(
        () => [
            {
                key: 'cidr',
                header: 'Prefiks',
                cell: (row) => (
                    <div style={{ paddingInlineStart: `${row.depth * 1.25}rem` }}>
                        {row.depth > 0 && (
                            <span className="sr-only">Bersarang {row.depth} tingkat: </span>
                        )}
                        <Link
                            href={route('admin.ipam.prefixes.show', row.id)}
                            className="font-mono font-medium text-primary hover:underline"
                        >
                            {row.cidr}
                        </Link>
                        {row.description && (
                            <p className="text-xs text-muted-foreground">{row.description}</p>
                        )}
                    </div>
                ),
            },
            {
                key: 'usage',
                header: 'Peruntukan',
                cell: (row) => (
                    <EnumBadge
                        map={IP_PREFIX_USAGE}
                        value={row.usage}
                        className="whitespace-nowrap"
                    />
                ),
            },
            {
                key: 'location',
                header: 'POP',
                className: 'whitespace-nowrap',
                cell: (row) => row.location?.code ?? <EmptyCell />,
            },
            {
                key: 'vlan',
                header: 'VLAN',
                className: 'whitespace-nowrap',
                cell: (row) => (row.vlan ? `${row.vlan.vid} - ${row.vlan.name}` : <EmptyCell />),
            },
            {
                key: 'size',
                header: 'Ukuran',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => formatCount(row.size),
            },
            {
                key: 'used',
                header: 'Terpakai',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) =>
                    // Containers are measured by their child prefixes and dynamic pools by size only.
                    row.usage === 'customer_static' || row.usage === 'infrastructure' ? (
                        formatCount(row.assigned + row.reserved)
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'free',
                header: 'Kosong',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => formatCount(row.free),
            },
            {
                key: 'percent',
                header: 'Pemakaian',
                cell: (row) => <PrefixUtilisation prefix={row} />,
            },
        ],
        [],
    );

    const newPrefix = can.manage ? (
        <Button type="button" variant="primary" onClick={() => setCreating(true)}>
            <Plus className="size-4" aria-hidden="true" />
            Buat Prefiks
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Alamat IP">
            <div className="space-y-6">
                <PageHeader
                    title="Alamat IP"
                    description="Prefiks yang terdaftar di perusahaan ini dan seberapa penuh tiap blok."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={route('admin.ipam.vlans.index')}>VLAN</Link>
                            </Button>
                            {newPrefix}
                        </>
                    }
                />

                <div className="grid grid-cols-2 items-end gap-3 rounded-lg border bg-card p-3 lg:grid-cols-5">
                    <div className="col-span-2 lg:col-span-1">
                        <Input
                            id="filter-search"
                            label="Cari"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
                            placeholder="Prefiks atau keterangan"
                        />
                    </div>
                    <NativeSelect
                        id="filter-family"
                        label="Versi IP"
                        value={params.family ?? ''}
                        onChange={(event) => set('family', event.target.value)}
                        options={familyOptions}
                    />
                    <NativeSelect
                        id="filter-usage"
                        label="Peruntukan"
                        value={params.usage ?? ''}
                        onChange={(event) => set('usage', event.target.value)}
                        options={usageOptions}
                    />
                    <SearchSelect
                        id="filter-pop"
                        label="POP"
                        value={params.location_id ?? ''}
                        onChange={(value) => set('location_id', value)}
                        options={popOptions}
                        placeholder="Semua POP"
                        emptyText="Tidak ada POP yang cocok."
                    />
                    <SearchSelect
                        id="filter-vlan"
                        label="VLAN"
                        value={params.vlan_id ?? ''}
                        onChange={(value) => set('vlan_id', value)}
                        options={vlanOptions}
                        placeholder="Semua VLAN"
                        emptyText="Tidak ada VLAN yang cocok."
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={prefixes}
                    loading={processing}
                    emptyTitle="Belum ada prefiks"
                    emptyDescription="Daftarkan prefiks pertama untuk mulai melacak seberapa penuh tiap blok."
                    emptyAction={newPrefix}
                    hasFilters={filtered}
                    onResetFilters={() =>
                        setMany({
                            search: '',
                            family: '',
                            usage: '',
                            location_id: '',
                            vlan_id: '',
                        })
                    }
                    itemLabel="prefiks"
                />

                {creating && (
                    <PrefixDialog
                        onClose={() => setCreating(false)}
                        locations={locations}
                        vlans={vlans}
                    />
                )}
            </div>
        </AdminLayout>
    );
}
