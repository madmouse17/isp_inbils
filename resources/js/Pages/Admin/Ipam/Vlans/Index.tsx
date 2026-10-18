import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { VlanDialog } from '@/Components/composite/ipam/VlanDialog';
import { Button, EmptyCell } from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';
import type { PopOption, VlanRow } from '@/types/ipam-odn';

interface IndexProps extends Record<string, unknown> {
    vlans: Paginated<VlanRow>;
    locations: PopOption[];
    can: { manage: boolean };
}

export default function Index({ vlans, locations, can }: IndexProps) {
    /** `'new'` registers a VLAN, a row edits it. */
    const [dialog, setDialog] = useState<'new' | VlanRow | null>(null);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const { set, processing } = useTableQuery({}, { only: ['vlans'] });

    const columns: DataTableColumn<VlanRow>[] = useMemo(() => {
        const base: DataTableColumn<VlanRow>[] = [
            {
                key: 'vid',
                header: 'ID VLAN',
                className: 'font-mono tabular-nums',
                cell: (row) => row.vid,
            },
            { key: 'name', header: 'Nama', cell: (row) => row.name },
            {
                key: 'location',
                header: 'POP',
                cell: (row) => row.location?.code ?? 'Seluruh perusahaan',
            },
            {
                key: 'prefixes',
                header: 'Prefiks',
                className: 'tabular-nums',
                cell: (row) =>
                    row.prefixes_count > 0 ? (
                        <Link
                            href={route('admin.ipam.prefixes.index', { vlan_id: row.id })}
                            className="font-medium text-primary-text hover:underline"
                        >
                            {row.prefixes_count}
                        </Link>
                    ) : (
                        0
                    ),
            },
            {
                key: 'description',
                header: 'Keterangan',
                cell: (row) => row.description ?? <EmptyCell />,
            },
        ];

        if (!can.manage) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <div className="flex flex-wrap gap-1">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            aria-label={`Ubah VLAN ${row.vid}`}
                            onClick={() => setDialog(row)}
                        >
                            Ubah
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive-outline"
                            aria-label={`Hapus VLAN ${row.vid}`}
                            onClick={() =>
                                setConfirm({
                                    title: `Hapus VLAN ${row.vid}?`,
                                    description: `${row.name} keluar dari register. VLAN hanya bisa dihapus selama tidak ada prefiks yang memakainya.`,
                                    confirmLabel: 'Hapus VLAN',
                                    href: route('admin.ipam.vlans.destroy', row.id),
                                })
                            }
                        >
                            Hapus
                        </Button>
                    </div>
                ),
            },
        ];
    }, [can.manage]);

    const newVlan = can.manage ? (
        <Button type="button" variant="primary" onClick={() => setDialog('new')}>
            <Plus className="size-4" aria-hidden="true" />
            Buat VLAN
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="VLAN">
            <div className="space-y-6">
                <PageHeader
                    title="VLAN"
                    description="ID VLAN yang dipakai jaringan, untuk seluruh perusahaan atau per POP."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={route('admin.ipam.prefixes.index')}>Alamat IP</Link>
                            </Button>
                            {newVlan}
                        </>
                    }
                />

                <DataTable
                    columns={columns}
                    data={vlans.data}
                    pagination={toPagination(vlans)}
                    onPageChange={(page) => set('page', page)}
                    loading={processing}
                    emptyTitle="Belum ada VLAN"
                    emptyDescription="Daftarkan ID VLAN yang dipakai agar prefiks bisa dikaitkan dengannya."
                    emptyAction={newVlan}
                />

                {dialog && (
                    <VlanDialog
                        onClose={() => setDialog(null)}
                        vlan={dialog === 'new' ? undefined : dialog}
                        locations={locations}
                    />
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
