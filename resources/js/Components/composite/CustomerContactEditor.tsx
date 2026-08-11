import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    PhoneInput,
    Switch,
    Textarea,
} from '@/Components/ui';
import type { CustomerContact } from '@/types/models';

interface CustomerContactEditorProps {
    customerId: number;
    contacts: CustomerContact[];
    canManage: boolean;
}

type ContactForm = {
    id: number | null;
    name: string;
    position: string;
    phone: string;
    email: string;
    is_primary: boolean;
    notes: string;
};

const emptyContact = (): ContactForm => ({
    id: null,
    name: '',
    position: '',
    phone: '',
    email: '',
    is_primary: false,
    notes: '',
});

export function CustomerContactEditor({ customerId, contacts, canManage }: CustomerContactEditorProps) {
    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm<ContactForm>(emptyContact());

    const startCreate = () => reset();

    const startEdit = (row: CustomerContact) => {
        setData('id', Number(row.id));
        setData('name', row.name ?? '');
        setData('position', row.position ?? '');
        setData('phone', row.phone ?? '');
        setData('email', row.email ?? '');
        setData('is_primary', Boolean(row.is_primary));
        setData('notes', row.notes ?? '');
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (data.id) {
            put(route('admin.customers.contacts.update', [customerId, data.id]), {
                preserveScroll: true,
                onSuccess: () => reset(),
            });
        } else {
            post(route('admin.customers.contacts.store', customerId), {
                preserveScroll: true,
                onSuccess: () => reset(),
            });
        }
    };

    const remove = (row: CustomerContact) => {
        if (window.confirm(`Delete contact ${row.name}?`)) {
            destroy(route('admin.customers.contacts.destroy', [customerId, row.id]), {
                preserveScroll: true,
            });
        }
    };

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="flex-row flex-wrap items-center justify-between space-y-0">
                    <CardTitle>Contacts</CardTitle>
                    <Button type="button" variant="success" onClick={startCreate}>Add Contact</Button>
                </CardHeader>
                <CardContent className="space-y-3">
                    {contacts.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">No contacts yet.</p>
                    ) : contacts.map((row) => (
                        <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">{row.name}</p>
                                <p className="text-sm text-muted-foreground">{[row.position, row.phone, row.email].filter(Boolean).join(' · ') || '—'}</p>
                            </div>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" size="sm" onClick={() => startEdit(row)}>Edit</Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => remove(row)}>Delete</Button>
                            </div>
                        </div>
                    ))}
                </CardContent>
            </Card>

            {canManage ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{data.id ? 'Edit Contact' : 'Add Contact'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                                <Input label="Position" value={data.position} onChange={(e) => setData('position', e.target.value)} error={errors.position} />
                                <PhoneInput label="Phone" value={data.phone} onChange={(value) => setData('phone', value)} error={errors.phone} />
                                <Input label="Email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} />
                            </div>
                            <Switch label="Primary contact" checked={data.is_primary} onCheckedChange={(checked) => setData('is_primary', checked)} />
                            <Textarea label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} rows={2} />
                            <div className="flex justify-end gap-2">
                                {data.id ? <Button type="button" variant="secondary" onClick={() => reset()}>Cancel</Button> : null}
                                <Button type="submit" variant="success" loading={processing}>{data.id ? 'Save' : 'Add'}</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
