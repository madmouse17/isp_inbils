import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { PrefixDialog } from '@/Components/composite/ipam/PrefixDialog';
import { PrefixUtilisation } from '@/Components/composite/ipam/PrefixUtilisation';
import { Badge, Button, Input, NativeSelect } from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatCount, usageLabels, usageOptions, usageVariants } from '@/lib/ipam';
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

export default function Index({ prefixes, filters, locations, vlans, can }: IndexProps) {
    const [creating, setCreating] = useState(false);
    const { params, set, processing } = useTableQuery(
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

    const columns: DataTableColumn<PrefixRow>[] = useMemo(
        () => [
            {
                key: 'cidr',
                header: 'Prefix',
                cell: (row) => (
                    <div style={{ paddingInlineStart: `${row.depth * 1.25}rem` }}>
                        {row.depth > 0 && (
                            <span className="sr-only">Nested {row.depth} deep: </span>
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
                header: 'Usage',
                cell: (row) => (
                    <Badge variant={usageVariants[row.usage]} className="whitespace-nowrap">
                        {usageLabels[row.usage]}
                    </Badge>
                ),
            },
            {
                key: 'location',
                header: 'POP',
                className: 'whitespace-nowrap',
                cell: (row) => row.location?.code ?? '—',
            },
            {
                key: 'vlan',
                header: 'VLAN',
                className: 'whitespace-nowrap',
                cell: (row) => (row.vlan ? `${row.vlan.vid} · ${row.vlan.name}` : '—'),
            },
            {
                key: 'size',
                header: 'Size',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => formatCount(row.size),
            },
            {
                key: 'used',
                header: 'Used',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) =>
                    // Containers are measured by their child prefixes and dynamic pools by size only.
                    row.usage === 'customer_static' || row.usage === 'infrastructure'
                        ? formatCount(row.assigned + row.reserved)
                        : '—',
            },
            {
                key: 'free',
                header: 'Free',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => formatCount(row.free),
            },
            {
                key: 'percent',
                header: 'Utilisation',
                cell: (row) => <PrefixUtilisation prefix={row} />,
            },
        ],
        [],
    );

    const newPrefix = can.manage ? (
        <Button type="button" variant="success" onClick={() => setCreating(true)}>
            <Plus className="size-4" aria-hidden="true" />
            New prefix
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Alamat IP">
            <div className="space-y-6">
                <PageHeader
                    title="Alamat IP"
                    description="Prefixes registered for this company and how full each one is."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={route('admin.ipam.vlans.index')}>VLANs</Link>
                            </Button>
                            {newPrefix}
                        </>
                    }
                />

                <div className="grid grid-cols-2 items-end gap-3 rounded-lg border bg-card p-3 lg:grid-cols-5">
                    <div className="col-span-2 lg:col-span-1">
                        <Input
                            id="filter-search"
                            label="Search"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
                            placeholder="Prefix or description"
                        />
                    </div>
                    <NativeSelect
                        id="filter-family"
                        label="IP version"
                        value={params.family ?? ''}
                        onChange={(event) => set('family', event.target.value)}
                        options={[
                            { value: '', label: 'IPv4 and IPv6' },
                            { value: '4', label: 'IPv4' },
                            { value: '6', label: 'IPv6' },
                        ]}
                    />
                    <NativeSelect
                        id="filter-usage"
                        label="Usage"
                        value={params.usage ?? ''}
                        onChange={(event) => set('usage', event.target.value)}
                        options={[{ value: '', label: 'All usages' }, ...usageOptions]}
                    />
                    <NativeSelect
                        id="filter-pop"
                        label="POP"
                        value={params.location_id ?? ''}
                        onChange={(event) => set('location_id', event.target.value)}
                        options={[
                            { value: '', label: 'All POPs' },
                            ...locations.map((pop) => ({
                                value: String(pop.id),
                                label: `${pop.code} — ${pop.name}`,
                            })),
                        ]}
                    />
                    <NativeSelect
                        id="filter-vlan"
                        label="VLAN"
                        value={params.vlan_id ?? ''}
                        onChange={(event) => set('vlan_id', event.target.value)}
                        options={[
                            { value: '', label: 'All VLANs' },
                            ...vlans.map((vlan) => ({
                                value: String(vlan.id),
                                label: `VLAN ${vlan.vid} · ${vlan.name}`,
                            })),
                        ]}
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={prefixes}
                    loading={processing}
                    emptyTitle={filtered ? 'No prefix matches the filters' : 'No prefixes yet'}
                    emptyDescription={
                        filtered
                            ? 'Clear a filter to see the rest of the address space.'
                            : 'Register your first prefix to start tracking how full each block is.'
                    }
                    emptyAction={filtered ? undefined : newPrefix}
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
