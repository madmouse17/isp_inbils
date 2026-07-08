import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Button, Card, CardContent, Input, Switch, Textarea, Table, TBody, TD, TH, THead, TR, Modal } from '@/Components/ui';
import type { CustomerContact } from '@/types/models';

interface ContactProps {
    customer: { id: number; code: string; name: string };
    contacts: { data: CustomerContact[] };
}

export default function Index({ customer, contacts }: ContactProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const list = contacts.data;
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        position: '',
        phone: '',
        email: '',
        is_primary: false,
        notes: '',
    });

    const openCreate = () => { reset(); setEditId(null); setModalOpen(true); };

    const openEdit = (c: CustomerContact) => {
        setData('name', c.name);
        setData('position', c.position ?? '');
        setData('phone', c.phone ?? '');
        setData('email', c.email ?? '');
        setData('is_primary', c.is_primary);
        setData('notes', c.notes ?? '');
        setEditId(c.id);
        setModalOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) {
            put(route('admin.customers.contacts.update', [customer.id, editId]), { onSuccess: () => setModalOpen(false) });
        } else {
            post(route('admin.customers.contacts.store', customer.id), { onSuccess: () => setModalOpen(false) });
        }
    };

    const remove = (c: CustomerContact) => {
        if (window.confirm(`Delete ${c.name}?`)) router.delete(route('admin.customers.contacts.destroy', [customer.id, c.id]));
    };

    return (
        <AdminLayout title="Customer Contacts">
            <div className="space-y-6">
                <PageHeader
                    title="Contacts"
                    subtitle={`${customer.code} — ${customer.name}`}
                    actions={(
                        <>
                            <Button type="button" variant="secondary" onClick={() => router.get(route('admin.customers.show', customer.id))}>Back</Button>
                            <Button type="button" onClick={openCreate}>Add Contact</Button>
                        </>
                    )}
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead><TR><TH>Name</TH><TH>Position</TH><TH>Phone</TH><TH>Email</TH><TH>Primary</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {list.length === 0 ? <TR><TD colSpan={6} className="py-10 text-center text-muted-foreground">No contacts.</TD></TR> :
                                list.map((c) => (
                                    <TR key={c.id}>
                                        <TD>{c.name}</TD>
                                        <TD>{c.position ?? '-'}</TD>
                                        <TD>{c.phone ?? '-'}</TD>
                                        <TD>{c.email ?? '-'}</TD>
                                        <TD>{c.is_primary ? <Badge variant="brand">Yes</Badge> : '-'}</TD>
                                        <TD>
                                            <div className="flex gap-2">
                                                <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(c)}>Edit</Button>
                                                <Button type="button" variant="ghost" size="sm" onClick={() => remove(c)}>Delete</Button>
                                            </div>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editId ? 'Edit Contact' : 'Add Contact'}>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Input label="Position" value={data.position} onChange={(e) => setData('position', e.target.value)} error={errors.position} />
                            <Input label="Phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} error={errors.phone} />
                            <Input label="Email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} />
                        </div>
                        <Switch label="Primary" checked={data.is_primary} onCheckedChange={(c) => setData('is_primary', c)} />
                        <Textarea label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} rows={2} />
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
