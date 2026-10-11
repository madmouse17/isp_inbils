import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { PonPortsDialog } from '@/Components/composite/odn/PonPortsDialog';
import { SplitterCard } from '@/Components/composite/odn/SplitterCard';
import { SplitterDialog } from '@/Components/composite/odn/SplitterDialog';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState } from '@/Components/ui';
import { usePermission } from '@/hooks/usePermission';
import type { InputOption, OdnAsset, PonPort, Splitter } from '@/types/ipam-odn';

interface AssetProps extends Record<string, unknown> {
    asset: OdnAsset;
    /** PON ports of an OLT; empty for an ODC or ODP. */
    ponPorts: PonPort[];
    /** Splitters of an ODC or ODP; empty for an OLT. */
    splitters: Splitter[];
    inputOptions: InputOption[];
    can: { manage: boolean; assign: boolean };
}

export default function Asset({ asset, ponPorts, splitters, inputOptions, can }: AssetProps) {
    const { can: allowed } = usePermission();
    const [adding, setAdding] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const isOlt = asset.asset_type === 'olt';
    const kind = asset.asset_type.toUpperCase();

    const ponColumns: DataTableColumn<PonPort>[] = useMemo(() => {
        const base: DataTableColumn<PonPort>[] = [
            {
                key: 'number',
                header: 'PON port',
                cell: (row) => `PON ${row.number}${row.label ? ` · ${row.label}` : ''}`,
            },
            {
                key: 'splitter',
                header: 'Splitter',
                cell: (row) =>
                    row.splitter_id === null ? 'Not connected' : `Splitter #${row.splitter_id}`,
            },
            {
                key: 'subscribers',
                header: 'Subscribers',
                className: 'tabular-nums',
                cell: (row) => row.subscribers,
            },
        ];

        if (!can.manage) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Actions',
                cell: (row) =>
                    // A connected port cannot be deleted: disconnect its splitter first.
                    row.splitter_id === null ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="danger"
                            aria-label={`Delete PON ${row.number}`}
                            onClick={() =>
                                setConfirm({
                                    title: `Delete PON ${row.number}?`,
                                    description: `The port leaves ${asset.code}. Later ports keep their numbers.`,
                                    confirmLabel: 'Delete port',
                                    href: route('admin.odn.ports.destroy', row.id),
                                })
                            }
                        >
                            Delete
                        </Button>
                    ) : null,
            },
        ];
    }, [can.manage, asset.code]);

    const addLabel = isOlt ? 'Add PON ports' : 'Add splitter';
    const add = can.manage ? (
        <Button type="button" variant="success" onClick={() => setAdding(true)}>
            <Plus className="size-4" aria-hidden="true" />
            {addLabel}
        </Button>
    ) : undefined;

    return (
        <AdminLayout title={`${asset.code} · ODN`}>
            <div className="space-y-6">
                <PageHeader
                    title={asset.code}
                    description={`${asset.name} · ${kind}${asset.location_path ? ` · ${asset.location_path}` : ''}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.odn.index')}>ODN</Link>
                            </Button>
                            {allowed('network_asset.view') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('admin.network-assets.show', asset.id)}>
                                        Asset record
                                    </Link>
                                </Button>
                            )}
                            {add}
                        </>
                    }
                />

                {isOlt ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>PON ports</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={ponColumns}
                                data={ponPorts}
                                emptyTitle="No PON ports yet"
                                emptyDescription="Add the ports of this OLT, then connect splitters to them."
                                emptyAction={add}
                            />
                        </CardContent>
                    </Card>
                ) : splitters.length === 0 ? (
                    <Card>
                        <CardContent>
                            <EmptyState
                                title={`No splitter in this ${kind}`}
                                description="A splitter gives it the output ports customers connect to."
                                action={add}
                            />
                        </CardContent>
                    </Card>
                ) : (
                    splitters.map((splitter) => (
                        <SplitterCard
                            key={splitter.id}
                            splitter={splitter}
                            inputOptions={inputOptions}
                            can={can}
                        />
                    ))
                )}

                {adding &&
                    (isOlt ? (
                        <PonPortsDialog onClose={() => setAdding(false)} olt={asset} />
                    ) : (
                        <SplitterDialog
                            onClose={() => setAdding(false)}
                            host={asset}
                            inputOptions={inputOptions}
                        />
                    ))}
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
