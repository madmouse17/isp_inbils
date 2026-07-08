import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Button, Card, CardContent, Input, Switch, Table, TBody, TD, TH, THead, TR, Modal } from '@/Components/ui';

interface OrgRow {
    id: number; parent_id: number | null; code: string; name: string; type: string;
    path?: string | null; is_active: boolean; children_count?: number;
}

interface IndexProps extends Record<string, unknown> {
    organizations: { data: OrgRow[] };
}

export default function Index({ organizations }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        code: '', name: '', type: 'branch', parent_id: '', address: '', phone: '', email: '', is_active: true,
    });

    const openCreate = () => { reset(); setEditId(null); setModalOpen(true); };
    const openEdit = (o: OrgRow) => {
        setData('code', o.code); setData('name', o.name); setData('type', o.type);
        setData('parent_id', o.parent_id ? String(o.parent_id) : '');
        setData('is_active', o.is_active); setEditId(o.id); setModalOpen(true);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) { put(route('admin.organizations.update', editId), { onSuccess: () => setModalOpen(false) }); }
        else { post(route('admin.organizations.store'), { onSuccess: () => setModalOpen(false) }); }
    };
    const remove = (o: OrgRow) => { if (window.confirm(`Delete ${o.name}?`)) router.delete(route('admin.organizations.destroy', o.id)); };

    return (
        <AdminLayout title="Organization">
            <div className="space-y-6">
                <PageHeader title="Organization Units" subtitle="Branch, area, unit, team hierarchy." actions={<Button type="button" onClick={openCreate}>Add Unit</Button>} />
                <Card><CardContent className="space-y-4 pt-6">
                    <Table>
                        <THead><TR><TH>Code</TH><TH>Name</TH><TH>Type</TH><TH>Path</TH><TH>Children</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                        <TBody>
                            {organizations.data.length === 0 ? (
                                <TR><TD colSpan={7} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                            ) : organizations.data.map((o) => (
                                <TR key={o.id}>
                                    <TD className="font-mono text-sm">{o.code}</TD>
                                    <TD>{o.name}</TD>
                                    <TD><Badge variant="neutral">{o.type}</Badge></TD>
                                    <TD className="text-sm text-surface-500">{o.path ?? '-'}</TD>
                                    <TD>{o.children_count ?? 0}</TD>
                                    <TD><Badge variant={o.is_active ? 'success' : 'danger'}>{o.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                    <TD>
                                        <div className="flex gap-2">
                                            <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(o)}>Edit</Button>
                                            <Button type="button" variant="ghost" size="sm" onClick={() => remove(o)}>Delete</Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </CardContent></Card>
                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editId ? 'Edit Unit' : 'Add Unit'}>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Code" value={data.code} onChange={(e) => setData('code', e.target.value)} error={errors.code} required />
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Input label="Type" value={data.type} onChange={(e) => setData('type', e.target.value)} required />
                            <Input label="Parent ID" value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)} placeholder="Leave empty for root" />
                            <Input label="Phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                            <Input label="Email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        </div>
                        <Input label="Address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                        <Switch label="Active" checked={data.is_active} onCheckedChange={(c) => setData('is_active', c)} />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                            <Button type="submit" loading={processing}>{editId ? 'Save' : 'Add'}</Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
