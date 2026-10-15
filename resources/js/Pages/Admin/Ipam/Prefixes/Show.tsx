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
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
} from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDateTime } from '@/lib/format';
import { formatCount } from '@/lib/ipam';
import { toPagination } from '@/lib/pagination';
import { IP_ADDRESS_STATUS, IP_PREFIX_USAGE, labelOf } from '@/lib/statuses';
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
            label: 'Peruntukan',
            value: <EnumBadge map={IP_PREFIX_USAGE} value={prefix.usage} />,
        },
        {
            label: 'Di dalam',
            value: prefix.parent ? (
                <Link
                    href={route('admin.ipam.prefixes.show', prefix.parent.id)}
                    className="font-mono font-medium text-primary hover:underline"
                >
                    {prefix.parent.cidr}
                </Link>
            ) : (
                'Tingkat atas'
            ),
        },
        { label: 'POP', value: prefix.location?.code ?? <EmptyCell /> },
        {
            label: 'VLAN',
            value: prefix.vlan ? `${prefix.vlan.vid} - ${prefix.vlan.name}` : <EmptyCell />,
        },
        { label: 'Ukuran', value: formatCount(prefix.size) },
        ...(holdsAddresses
            ? [
                  { label: 'Dapat dipakai', value: formatCount(prefix.usable) },
                  {
                      label: labelOf(IP_ADDRESS_STATUS, 'assigned'),
                      value: formatCount(prefix.assigned),
                  },
                  {
                      label: labelOf(IP_ADDRESS_STATUS, 'reserved'),
                      value: formatCount(prefix.reserved),
                  },
                  { label: 'Kosong', value: formatCount(prefix.free) },
              ]
            : []),
    ];

    const childColumns: DataTableColumn<PrefixRow>[] = useMemo(
        () => [
            {
                key: 'cidr',
                header: 'Prefiks',
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
                header: 'Peruntukan',
                cell: (row) => <EnumBadge map={IP_PREFIX_USAGE} value={row.usage} />,
            },
            { key: 'location', header: 'POP', cell: (row) => row.location?.code ?? <EmptyCell /> },
            {
                key: 'size',
                header: 'Ukuran',
                className: 'tabular-nums',
                cell: (row) => formatCount(row.size),
            },
            {
                key: 'percent',
                header: 'Pemakaian',
                cell: (row) => <PrefixUtilisation prefix={row} />,
            },
        ],
        [],
    );

    const addressColumns: DataTableColumn<IpAddressRow>[] = useMemo(() => {
        const columns: DataTableColumn<IpAddressRow>[] = [
            {
                key: 'address',
                header: 'Alamat',
                cell: (row) => <span className="font-mono">{row.address}</span>,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={IP_ADDRESS_STATUS} value={row.status} />,
            },
            {
                key: 'holder',
                header: 'Dipakai oleh',
                cell: (row) =>
                    row.holder ? (
                        <Link
                            href={row.holder.href}
                            className="font-medium text-primary hover:underline"
                        >
                            {row.holder.label}
                        </Link>
                    ) : (
                        <EmptyCell />
                    ),
            },
            { key: 'note', header: 'Catatan', cell: (row) => row.note ?? <EmptyCell /> },
            {
                key: 'assigned_at',
                header: 'Ditambahkan',
                cell: (row) => formatDateTime(row.assigned_at),
            },
        ];

        if (!can.manage && !can.assign) return columns;

        return [
            ...columns,
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => {
                    // An asset keeps its address on its own page; the register refuses to release it.
                    if (row.holder?.type === 'asset') {
                        return (
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('admin.network-assets.edit', row.holder.id)}>
                                    Ubah aset
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
                            aria-label={`Lepas ${row.address}`}
                            onClick={() =>
                                setConfirm({
                                    title: `Lepas ${row.address}?`,
                                    description: row.holder
                                        ? `${row.holder.label} kehilangan alamat statis ini dan alamat kembali ke pool.`
                                        : 'Pencadangan dihapus dan alamat dapat dibagikan lagi.',
                                    confirmLabel: 'Lepas alamat',
                                    href: route('admin.ipam.addresses.destroy', row.id),
                                })
                            }
                        >
                            Lepas
                        </Button>
                    );
                },
            },
        ];
    }, [can.manage, can.assign]);

    const reserve = can.manage ? (
        <Button type="button" variant="primary" onClick={() => setReserving(true)}>
            <Plus className="size-4" aria-hidden="true" />
            Cadangkan alamat
        </Button>
    ) : undefined;

    return (
        <AdminLayout title={prefix.cidr}>
            <div className="space-y-6">
                <PageHeader
                    title={prefix.cidr}
                    description={prefix.description ?? labelOf(IP_PREFIX_USAGE, prefix.usage)}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.ipam.prefixes.index')}>Kembali</Link>
                            </Button>
                            {can.manage && (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditing(true)}
                                    >
                                        Ubah
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="text-destructive-text"
                                        onClick={() =>
                                            setConfirm({
                                                title: `Hapus ${prefix.cidr}?`,
                                                description:
                                                    'Prefiks keluar dari register. Prefiks hanya bisa dihapus selama tidak memuat prefiks lain dan alamat.',
                                                confirmLabel: 'Hapus prefiks',
                                                href: route(
                                                    'admin.ipam.prefixes.destroy',
                                                    prefix.id,
                                                ),
                                            })
                                        }
                                    >
                                        Hapus
                                    </Button>
                                </>
                            )}
                            {reserve}
                        </>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Detail</CardTitle>
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
                                    ? 'Bagian blok ini yang diberikan ke prefiks di dalamnya.'
                                    : prefix.usage === 'customer_dynamic'
                                      ? 'Router membagikan alamat ini, jadi hanya ukurannya yang dicatat.'
                                      : prefix.family === 6
                                        ? 'Alamat IPv6 diketik manual, jadi ruang kosong tidak dihitung.'
                                        : 'Alamat yang dipakai dan dicadangkan dari yang dapat dipakai.'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {(childPrefixes.length > 0 || prefix.usage === 'container') && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Prefiks di dalam</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={childColumns}
                                data={childPrefixes}
                                emptyTitle="Tidak ada prefiks di dalam blok ini"
                                emptyDescription="Prefiks yang Anda daftarkan di rentang ini muncul di sini."
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Alamat</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={addressColumns}
                            data={addresses.data}
                            pagination={toPagination(addresses)}
                            onPageChange={(page) => set('page', page)}
                            loading={processing}
                            emptyTitle="Belum ada alamat di blok ini"
                            emptyDescription="Berikan dari halaman langganan, atau cadangkan untuk gateway atau loopback."
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
