import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Modal, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';

interface InstallRow {
    id: number; installed_at: string; removed_at?: string | null; removal_reason?: string | null;
    location?: { name: string; path?: string } | null;
}

interface AssetData {
    id: number; code: string; name: string; asset_type: string; serial_number?: string | null;
    mac_address?: string | null; ip_address?: string | null; management_ip?: string | null;
    status: string; ownership: string; vendor?: string | null; model?: string | null;
    purchase_date?: string | null; purchase_price?: string | null; warranty_expiry?: string | null;
    notes?: string | null; installed_at?: string | null; retired_at?: string | null;
    location?: { name: string; path?: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    installations?: InstallRow[];
}

interface ShowProps extends Record<string, unknown> {
    asset: { data: AssetData };
}

export default function Show({ asset }: ShowProps) {
    const a = asset.data;
    const [actionModal, setActionModal] = useState<'install' | 'remove' | 'maintenance' | 'damage' | 'repair' | 'resume' | 'retire' | null>(null);
    const { data, setData, post, processing } = useForm({ reason: '', location_id: '', customer_id: '', subscription_id: '' });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;
        post(route(`admin.network-assets.${actionModal}`, a.id), { onSuccess: () => setActionModal(null) });
    };

    return (
        <AdminLayout title={a.name}>
            <div className="space-y-6">
                <PageHeader
                    title={a.name}
                    subtitle={`${a.code} · ${a.status}`}
                    actions={<Button type="button" variant="secondary" onClick={() => router.get(route('admin.network-assets.index'))}>Back</Button>}
                />
                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader><CardTitle>Asset Details</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                            <p><span className="text-muted-foreground">Type: </span>{a.asset_type}</p>
                            <p><span className="text-muted-foreground">Serial: </span>{a.serial_number ?? '-'}</p>
                            <p><span className="text-muted-foreground">MAC: </span>{a.mac_address ?? '-'}</p>
                            <p><span className="text-muted-foreground">IP: </span>{a.ip_address ?? '-'}</p>
                            <p><span className="text-muted-foreground">Mgmt IP: </span>{a.management_ip ?? '-'}</p>
                            <p><span className="text-muted-foreground">Ownership: </span>{a.ownership}</p>
                            <p><span className="text-muted-foreground">Vendor: </span>{a.vendor ?? '-'}</p>
                            <p><span className="text-muted-foreground">Model: </span>{a.model ?? '-'}</p>
                            <p><span className="text-muted-foreground">Location: </span>{a.location?.name ?? '-'}</p>
                            <p><span className="text-muted-foreground">Path: </span>{a.location?.path ?? '-'}</p>
                            <p><span className="text-muted-foreground">Customer: </span>{a.customer?.name ?? '-'}</p>
                            <p><span className="text-muted-foreground">Subscription: </span>{a.subscription?.code ?? '-'}</p>
                            <p><span className="text-muted-foreground">Installed: </span>{a.installed_at ?? '-'}</p>
                            <p><span className="text-muted-foreground">Retired: </span>{a.retired_at ?? '-'}</p>
                            {a.notes && <p className="md:col-span-2"><span className="text-muted-foreground">Notes: </span>{a.notes}</p>}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>Lifecycle Actions</CardTitle></CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {a.status === 'available' && <Button type="button" onClick={() => setActionModal('install')}>Install</Button>}
                                {a.status === 'installed' && <Button type="button" variant="outline" onClick={() => setActionModal('remove')}>Remove</Button>}
                                {a.status === 'installed' && <Button type="button" variant="outline" onClick={() => setActionModal('maintenance')}>Maintenance</Button>}
                                {a.status === 'maintenance' && <Button type="button" onClick={() => setActionModal('resume')}>Resume</Button>}
                                {(a.status === 'installed' || a.status === 'maintenance') && <Button type="button" variant="danger" onClick={() => setActionModal('damage')}>Damage</Button>}
                                {a.status === 'damaged' && <Button type="button" onClick={() => setActionModal('repair')}>Repair</Button>}
                                {a.status !== 'retired' && <Button type="button" variant="danger" onClick={() => setActionModal('retire')}>Retire</Button>}
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader><CardTitle>Installation History</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Location</TH><TH>Installed At</TH><TH>Removed At</TH><TH>Reason</TH></TR></THead>
                            <TBody>
                                {(a.installations ?? []).length === 0 ? <TR><TD colSpan={4} className="py-10 text-center text-muted-foreground">No installations.</TD></TR> :
                                (a.installations ?? []).map((i) => (
                                    <TR key={i.id}>
                                        <TD>{i.location?.name ?? '-'}</TD>
                                        <TD className="text-sm">{i.installed_at}</TD>
                                        <TD className="text-sm">{i.removed_at ?? '—'}</TD>
                                        <TD>{i.removal_reason ?? '-'}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
                <Modal open={actionModal !== null} onClose={() => setActionModal(null)} title={actionModal ? actionModal.charAt(0).toUpperCase() + actionModal.slice(1) : ''}>
                    <form onSubmit={doAction} className="space-y-4">
                        {actionModal === 'install' && (
                            <Input label="Location ID" value={data.location_id} onChange={(e) => setData('location_id', e.target.value)} required />
                        )}
                        {['remove', 'maintenance', 'damage', 'retire'].includes(actionModal ?? '') && (
                            <Input label="Reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} required />
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setActionModal(null)}>Cancel</Button>
                            <Button type="submit" loading={processing}>{actionModal === 'retire' || actionModal === 'damage' ? 'Confirm' : actionModal === 'repair' || actionModal === 'resume' ? 'Confirm' : 'Submit'}</Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
