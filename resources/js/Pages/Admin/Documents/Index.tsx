import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Button, Card, CardContent, Input, Switch, Table, TBody, TD, TH, THead, TR, Modal } from '@/Components/ui';

interface DtRow {
    id: number; name: string; code: string; applies_to?: string | null;
    is_required: boolean; expiry_days?: number | null; is_active: boolean;
}

interface IndexProps extends Record<string, unknown> {
    documentTypes: { data: DtRow[] };
}

export default function Index({ documentTypes }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '', code: '', applies_to: '', is_required: false, expiry_days: '', is_active: true,
    });

    const openCreate = () => { reset(); setEditId(null); setModalOpen(true); };
    const openEdit = (d: DtRow) => {
        setData('name', d.name); setData('code', d.code); setData('applies_to', d.applies_to ?? '');
        setData('is_required', d.is_required); setData('expiry_days', d.expiry_days ? String(d.expiry_days) : '');
        setData('is_active', d.is_active); setEditId(d.id); setModalOpen(true);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) { put(route('admin.documents.update', editId), { onSuccess: () => setModalOpen(false) }); }
        else { post(route('admin.documents.store'), { onSuccess: () => setModalOpen(false) }); }
    };
    const remove = (d: DtRow) => { if (window.confirm(`Delete ${d.name}?`)) router.delete(route('admin.documents.destroy', d.id)); };

    return (
        <AdminLayout title="Document Types">
            <div className="space-y-6">
                <PageHeader title="Document Types" subtitle="Business document categories. Files stored via Spatie MediaLibrary." actions={<Button type="button" onClick={openCreate}>Add Type</Button>} />
                <Card><CardContent className="space-y-4 pt-6">
                    <Table>
                        <THead><TR><TH>Name</TH><TH>Code</TH><TH>Applies To</TH><TH>Required</TH><TH>Expiry</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                        <TBody>
                            {documentTypes.data.length === 0 ? (
                                <TR><TD colSpan={7} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                            ) : documentTypes.data.map((d) => (
                                <TR key={d.id}>
                                    <TD>{d.name}</TD>
                                    <TD className="font-mono text-sm">{d.code}</TD>
                                    <TD>{d.applies_to ?? '-'}</TD>
                                    <TD>{d.is_required ? <Badge variant="brand">Yes</Badge> : '-'}</TD>
                                    <TD>{d.expiry_days ? `${d.expiry_days} days` : '-'}</TD>
                                    <TD><Badge variant={d.is_active ? 'success' : 'danger'}>{d.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                    <TD>
                                        <div className="flex gap-2">
                                            <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(d)}>Edit</Button>
                                            <Button type="button" variant="ghost" size="sm" onClick={() => remove(d)}>Delete</Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </CardContent></Card>
                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editId ? 'Edit Type' : 'Add Type'}>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Input label="Code" value={data.code} onChange={(e) => setData('code', e.target.value)} error={errors.code} required />
                            <Input label="Applies To" value={data.applies_to} onChange={(e) => setData('applies_to', e.target.value)} placeholder="Customer, Employee, Vehicle..." />
                            <Input label="Expiry (days)" type="number" value={data.expiry_days} onChange={(e) => setData('expiry_days', e.target.value)} />
                        </div>
                        <div className="flex gap-6">
                            <Switch label="Required" checked={data.is_required} onCheckedChange={(c) => setData('is_required', c)} />
                            <Switch label="Active" checked={data.is_active} onCheckedChange={(c) => setData('is_active', c)} />
                        </div>
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
