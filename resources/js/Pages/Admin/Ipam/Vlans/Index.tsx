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
import { Button } from '@/Components/ui';
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
                header: 'VLAN ID',
                className: 'font-mono tabular-nums',
                cell: (row) => row.vid,
            },
            { key: 'name', header: 'Name', cell: (row) => row.name },
            {
                key: 'location',
                header: 'POP',
                cell: (row) => row.location?.code ?? 'Company-wide',
            },
            {
                key: 'prefixes',
                header: 'Prefixes',
                className: 'tabular-nums',
                cell: (row) =>
                    row.prefixes_count > 0 ? (
                        <Link
                            href={route('admin.ipam.prefixes.index', { vlan_id: row.id })}
                            className="font-medium text-primary hover:underline"
                        >
                            {row.prefixes_count}
                        </Link>
                    ) : (
                        0
                    ),
            },
            { key: 'description', header: 'Description', cell: (row) => row.description ?? '-' },
        ];

        if (!can.manage) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Actions',
                cell: (row) => (
                    <div className="flex flex-wrap gap-1">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            aria-label={`Edit VLAN ${row.vid}`}
                            onClick={() => setDialog(row)}
                        >
                            Edit
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="danger"
                            aria-label={`Delete VLAN ${row.vid}`}
                            onClick={() =>
                                setConfirm({
                                    title: `Delete VLAN ${row.vid}?`,
                                    description: `${row.name} leaves the register. It can only be deleted while no prefix uses it.`,
                                    confirmLabel: 'Delete VLAN',
                                    href: route('admin.ipam.vlans.destroy', row.id),
                                })
                            }
                        >
                            Delete
                        </Button>
                    </div>
                ),
            },
        ];
    }, [can.manage]);

    const newVlan = can.manage ? (
        <Button type="button" variant="primary" onClick={() => setDialog('new')}>
            <Plus className="size-4" aria-hidden="true" />
            New VLAN
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="VLANs">
            <div className="space-y-6">
                <PageHeader
                    title="VLANs"
                    description="The VLAN IDs your network uses, company-wide or per POP."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={route('admin.ipam.prefixes.index')}>IP Addresses</Link>
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
                    emptyTitle="No VLANs yet"
                    emptyDescription="Register the VLAN IDs you use so prefixes can be tied to them."
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
