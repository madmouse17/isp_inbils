import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Switch, Table, TBody, TD, TH, THead, TR, Modal } from '@/Components/ui';

interface VehRow {
    id: number; plate_number: string; type?: string | null; brand?: string | null; model?: string | null; is_active: boolean;
}

interface IndexProps extends Record<string, unknown> {
    vehicles: { data: VehRow[]; current_page: number; last_page: number };
}

export default function Index({ vehicles }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        plate_number: '', type: '', brand: '', model: '', purchase_date: '', is_active: true, notes: '',
    });

    const openCreate = () => { reset(); setEditId(null); setModalOpen(true); };
    const openEdit = (v: VehRow) => {
        setData('plate_number', v.plate_number); setData('type', v.type ?? ''); setData('brand', v.brand ?? '');
        setData('model', v.model ?? ''); setData('is_active', v.is_active); setEditId(v.id); setModalOpen(true);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) { put(route('admin.vehicles.update', editId), { onSuccess: () => setModalOpen(false) }); }
        else { post(route('admin.vehicles.store'), { onSuccess: () => setModalOpen(false) }); }
    };
    const remove = (v: VehRow) => { if (window.confirm(`Delete ${v.plate_number}?`)) router.delete(route('admin.vehicles.destroy', v.id)); };

    return (
        <AdminLayout title="Vehicles">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Vehicles</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Technician transport.</p>
                    </div>
                    <Button type="button" onClick={openCreate}>Add Vehicle</Button>
                </div>
                <Card><CardContent>
                    <Table>
                        <THead><TR><TH>Plate</TH><TH>Type</TH><TH>Brand</TH><TH>Model</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                        <TBody>
                            {vehicles.data.map((v) => (
                                <TR key={v.id}>
                                    <TD className="font-mono">{v.plate_number}</TD>
                                    <TD>{v.type ?? '-'}</TD>
                                    <TD>{v.brand ?? '-'}</TD>
                                    <TD>{v.model ?? '-'}</TD>
                                    <TD><Badge variant={v.is_active ? 'success' : 'danger'}>{v.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                    <TD>
                                        <div className="flex gap-2">
                                            <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(v)}>Edit</Button>
                                            <Button type="button" variant="ghost" size="sm" onClick={() => remove(v)}>Delete</Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </CardContent></Card>
                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editId ? 'Edit Vehicle' : 'Add Vehicle'}>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Plate Number" value={data.plate_number} onChange={(e) => setData('plate_number', e.target.value)} error={errors.plate_number} required />
                            <Input label="Type" value={data.type} onChange={(e) => setData('type', e.target.value)} placeholder="motorcycle, car, truck" />
                            <Input label="Brand" value={data.brand} onChange={(e) => setData('brand', e.target.value)} />
                            <Input label="Model" value={data.model} onChange={(e) => setData('model', e.target.value)} />
                            <Input label="Purchase Date" type="date" value={data.purchase_date} onChange={(e) => setData('purchase_date', e.target.value)} />
                            <div className="flex items-end"><Switch label="Active" checked={data.is_active} onCheckedChange={(c) => setData('is_active', c)} /></div>
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
