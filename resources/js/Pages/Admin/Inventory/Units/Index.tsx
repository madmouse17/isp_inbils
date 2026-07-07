import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, Input, Modal, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import type { Unit } from '@/types/inventory';

interface IndexProps extends Record<string, unknown> {
    units: { data: Unit[]; current_page: number; last_page: number; per_page: number; total: number };
    can: { create: boolean };
}

export default function Index({ units, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Unit | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({ name: '', symbol: '' });

    const openCreate = () => {
        setEditing(null);
        reset();
        setModalOpen(true);
    };

    const openEdit = (u: Unit) => {
        setEditing(u);
        setData({ name: u.name, symbol: u.symbol });
        setModalOpen(true);
    };

    const submitForm = (e: FormEvent) => {
        e.preventDefault();
        if (editing) {
            put(route('admin.units.update', editing.id), { onSuccess: () => { setModalOpen(false); reset(); } });
        } else {
            post(route('admin.units.store'), { onSuccess: () => { setModalOpen(false); reset(); } });
        }
    };

    const remove = (u: Unit) => {
        if (window.confirm(`Delete ${u.name}?`)) router.delete(route('admin.units.destroy', u.id));
    };

    return (
        <AdminLayout title="Units">
            <div className="space-y-6">
                <PageHeader
                    title="Units"
                    subtitle="Manage measurement units."
                    actions={can.create && <Button type="button" onClick={openCreate}>Create</Button>}
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead><TR><TH>Name</TH><TH>Symbol</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {units.data.length === 0 ? (
                                    <TR><TD colSpan={3} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                                ) : (
                                    units.data.map((u) => (
                                        <TR key={u.id}>
                                            <TD>{u.name}</TD>
                                            <TD className="font-mono">{u.symbol}</TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(u)}>Edit</Button>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => remove(u)}>Delete</Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination currentPage={units.current_page} lastPage={units.last_page} onPageChange={(page) => router.get(route('admin.units.index'), { page })} />
                    </CardContent>
                </Card>
            </div>

            <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit Unit' : 'Create Unit'}>
                <form onSubmit={submitForm} className="space-y-4">
                    <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                    <Input label="Symbol" value={data.symbol} onChange={(e) => setData('symbol', e.target.value)} error={errors.symbol} required />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                        <Button type="submit" loading={processing}>{editing ? 'Save' : 'Create'}</Button>
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
