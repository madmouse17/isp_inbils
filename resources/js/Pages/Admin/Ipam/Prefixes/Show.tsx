import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { PrefixDialog } from '@/Components/composite/ipam/PrefixDialog';
import { PrefixUtilisation } from '@/Components/composite/ipam/PrefixUtilisation';
import { ReserveAddressDialog } from '@/Components/composite/ipam/ReserveAddressDialog';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDateTime } from '@/lib/format';
import { formatCount, usageLabels, usageVariants } from '@/lib/ipam';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';
import type {
    IpAddressRow,
    PopOption,
    PrefixDetail,
    PrefixRow,
    VlanOption,
} from '@/types/ipam-odn';

interface ShowProps extends Record<string, unknown> {
    prefix: PrefixDetail;
    children: PrefixRow[];
    addresses: Paginated<IpAddressRow>;
    locations: PopOption[];
    vlans: VlanOption[];
    can: { manage: boolean; assign: boolean };
}

export default function Show({
    prefix,
    children: childPrefixes,
    addresses,
    locations,
    vlans,
    can,
}: ShowProps) {
    const [editing, setEditing] = useState(false);
    const [reserving, setReserving] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const { set, processing } = useTableQuery({}, { only: ['addresses'] });
    const holdsAddresses = prefix.usage === 'customer_static' || prefix.usage === 'infrastructure';

    const details: { label: string; value: ReactNode }[] = [
        {
            label: 'Usage',
            value: <Badge variant={usageVariants[prefix.usage]}>{usageLabels[prefix.usage]}</Badge>,
        },
        {
            label: 'Inside',
            value: prefix.parent ? (
                <Link
                    href={route('admin.ipam.prefixes.show', prefix.parent.id)}
                    className="font-mono font-medium text-primary hover:underline"
                >
                    {prefix.parent.cidr}
                </Link>
            ) : (
                'Top level'
            ),
        },
        { label: 'POP', value: prefix.location?.code ?? '-' },
        { label: 'VLAN', value: prefix.vlan ? `${prefix.vlan.vid} · ${prefix.vlan.name}` : '-' },
        { label: 'Size', value: formatCount(prefix.size) },
        ...(holdsAddresses
            ? [
                  { label: 'Usable', value: formatCount(prefix.usable) },
                  { label: 'Assigned', value: formatCount(prefix.assigned) },
                  { label: 'Reserved', value: formatCount(prefix.reserved) },
                  { label: 'Free', value: formatCount(prefix.free) },
              ]
            : []),
    ];

    const childColumns: DataTableColumn<PrefixRow>[] = useMemo(
        () => [
            {
                key: 'cidr',
                header: 'Prefix',
                cell: (row) => (
                    <Link
                        href={route('admin.ipam.prefixes.show', row.id)}
                        className="font-mono font-medium text-primary hover:underline"
                    >
                        {row.cidr}
                    </Link>
                ),
            },
            {
                key: 'usage',
                header: 'Usage',
                cell: (row) => (
                    <Badge variant={usageVariants[row.usage]}>{usageLabels[row.usage]}</Badge>
                ),
            },
            { key: 'location', header: 'POP', cell: (row) => row.location?.code ?? '-' },
            {
                key: 'size',
                header: 'Size',
                className: 'tabular-nums',
                cell: (row) => formatCount(row.size),
            },
            {
                key: 'percent',
                header: 'Utilisation',
                cell: (row) => <PrefixUtilisation prefix={row} />,
            },
        ],
        [],
    );

    const addressColumns: DataTableColumn<IpAddressRow>[] = useMemo(() => {
        const columns: DataTableColumn<IpAddressRow>[] = [
            {
                key: 'address',
                header: 'Address',
                cell: (row) => <span className="font-mono">{row.address}</span>,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => (
                    <Badge variant={row.status === 'assigned' ? 'info' : 'secondary'}>
                        {row.status === 'assigned' ? 'Assigned' : 'Reserved'}
                    </Badge>
                ),
            },
            {
                key: 'holder',
                header: 'Held by',
                cell: (row) =>
                    row.holder ? (
                        <Link
                            href={row.holder.href}
                            className="font-medium text-primary hover:underline"
                        >
                            {row.holder.label}
                        </Link>
                    ) : (
                        '-'
                    ),
            },
            { key: 'note', header: 'Note', cell: (row) => row.note ?? '-' },
            {
                key: 'assigned_at',
                header: 'Added',
                cell: (row) => formatDateTime(row.assigned_at),
            },
        ];

        if (!can.manage && !can.assign) return columns;

        return [
            ...columns,
            {
                key: 'actions',
                header: 'Actions',
                cell: (row) => {
                    // An asset keeps its address on its own page; the register refuses to release it.
                    if (row.holder?.type === 'asset') {
                        return (
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('admin.network-assets.edit', row.holder.id)}>
                                    Edit asset
                                </Link>
                            </Button>
                        );
                    }

                    if (!(row.holder === null ? can.manage : can.assign)) return null;

                    return (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            aria-label={`Release ${row.address}`}
                            onClick={() =>
                                setConfirm({
                                    title: `Release ${row.address}?`,
                                    description: row.holder
                                        ? `${row.holder.label} loses this static address and it returns to the pool.`
                                        : 'The reservation is removed and the address can be handed out again.',
                                    confirmLabel: 'Release address',
                                    href: route('admin.ipam.addresses.destroy', row.id),
                                })
                            }
                        >
                            Release
                        </Button>
                    );
                },
            },
        ];
    }, [can.manage, can.assign]);

    const reserve = can.manage ? (
        <Button type="button" variant="primary" onClick={() => setReserving(true)}>
            <Plus className="size-4" aria-hidden="true" />
            Reserve address
        </Button>
    ) : undefined;

    return (
        <AdminLayout title={prefix.cidr}>
            <div className="space-y-6">
                <PageHeader
                    title={prefix.cidr}
                    description={prefix.description ?? usageLabels[prefix.usage]}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.ipam.prefixes.index')}>Back</Link>
                            </Button>
                            {can.manage && (
                                <>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setEditing(true)}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="danger"
                                        onClick={() =>
                                            setConfirm({
                                                title: `Delete ${prefix.cidr}?`,
                                                description:
                                                    'The prefix leaves the register. It can only be deleted while it holds no child prefixes and no addresses.',
                                                confirmLabel: 'Delete prefix',
                                                href: route(
                                                    'admin.ipam.prefixes.destroy',
                                                    prefix.id,
                                                ),
                                            })
                                        }
                                    >
                                        Delete
                                    </Button>
                                </>
                            )}
                            {reserve}
                        </>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            {details.map(({ label, value }) => (
                                <p key={label}>
                                    <span className="text-muted-foreground">{label}: </span>
                                    {value}
                                </p>
                            ))}
                        </div>
                        <div className="space-y-1 text-sm">
                            <PrefixUtilisation prefix={prefix} />
                            <p className="text-muted-foreground">
                                {prefix.usage === 'container'
                                    ? 'Share of this block given to the prefixes inside it.'
                                    : prefix.usage === 'customer_dynamic'
                                      ? 'A router hands out these addresses, so only the size is tracked.'
                                      : prefix.family === 6
                                        ? 'IPv6 addresses are typed by hand, so free space is not counted.'
                                        : 'Assigned and reserved addresses out of the usable ones.'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {(childPrefixes.length > 0 || prefix.usage === 'container') && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Prefixes inside</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={childColumns}
                                data={childPrefixes}
                                emptyTitle="No prefixes inside this block"
                                emptyDescription="Prefixes you register within this range appear here."
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Addresses</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={addressColumns}
                            data={addresses.data}
                            pagination={toPagination(addresses)}
                            onPageChange={(page) => set('page', page)}
                            loading={processing}
                            emptyTitle="No addresses in this block"
                            emptyDescription="Assign one from a subscription page, or reserve one for a gateway or loopback."
                            emptyAction={reserve}
                        />
                    </CardContent>
                </Card>

                {editing && (
                    <PrefixDialog
                        onClose={() => setEditing(false)}
                        prefix={prefix}
                        locations={locations}
                        vlans={vlans}
                    />
                )}
                {reserving && (
                    <ReserveAddressDialog onClose={() => setReserving(false)} prefix={prefix} />
                )}
                {confirm && (
                    <ConfirmRequestDialog
                        key={confirm.href}
                        request={confirm}
                        onClose={() => setConfirm(null)}
                    />
                )}
            </div>
        </AdminLayout>
    );
}
