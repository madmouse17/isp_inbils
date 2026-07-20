import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Switch,
    Textarea,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Modal,
} from '@/Components/ui';
import type { CustomerAddress } from '@/types/models';

interface AddressProps {
    customer: { id: number; code: string; name: string };
    addresses: { data: CustomerAddress[] };
}

export default function Index({ customer, addresses }: AddressProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const addrList = addresses.data;
    const { data, setData, post, put, processing, errors, reset } = useForm({
        label: '',
        address: '',
        city: '',
        postal_code: '',
        is_installation_point: false,
        is_primary: false,
        notes: '',
    });

    const openCreate = () => {
        reset();
        setEditId(null);
        setModalOpen(true);
    };

    const openEdit = (a: CustomerAddress) => {
        setData('label', a.label);
        setData('address', a.address);
        setData('city', a.city ?? '');
        setData('postal_code', a.postal_code ?? '');
        setData('is_installation_point', a.is_installation_point);
        setData('is_primary', a.is_primary);
        setData('notes', a.notes ?? '');
        setEditId(a.id);
        setModalOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) {
            put(route('admin.customers.addresses.update', [customer.id, editId]), {
                onSuccess: () => setModalOpen(false),
            });
        } else {
            post(route('admin.customers.addresses.store', customer.id), {
                onSuccess: () => setModalOpen(false),
            });
        }
    };

    const remove = (a: CustomerAddress) => {
        if (window.confirm(`Delete ${a.label}?`))
            router.delete(route('admin.customers.addresses.destroy', [customer.id, a.id]));
    };

    return (
        <AdminLayout title="Customer Addresses">
            <div className="space-y-6">
                <PageHeader
                    title="Addresses"
                    subtitle={`${customer.code} — ${customer.name}`}
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.get(route('admin.customers.show', customer.id))
                                }
                            >
                                Back
                            </Button>
                            <Button type="button" onClick={openCreate}>
                                Add Address
                            </Button>
                        </>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Label</TH>
                                    <TH>Address</TH>
                                    <TH>City</TH>
                                    <TH>Postal</TH>
                                    <TH>Install</TH>
                                    <TH>Primary</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {addrList.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No addresses.
                                        </TD>
                                    </TR>
                                ) : (
                                    addrList.map((a) => (
                                        <TR key={a.id}>
                                            <TD>{a.label}</TD>
                                            <TD>{a.address}</TD>
                                            <TD>{a.city ?? '-'}</TD>
                                            <TD>{a.postal_code ?? '-'}</TD>
                                            <TD>
                                                {a.is_installation_point ? (
                                                    <Badge variant="success">Yes</Badge>
                                                ) : (
                                                    '-'
                                                )}
                                            </TD>
                                            <TD>
                                                {a.is_primary ? (
                                                    <Badge variant="brand">Yes</Badge>
                                                ) : (
                                                    '-'
                                                )}
                                            </TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(a)}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(a)}
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Edit Address' : 'Add Address'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <Input
                            label="Label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            error={errors.label}
                            required
                        />
                        <Textarea
                            label="Address"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                            error={errors.address}
                            required
                            rows={2}
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="City"
                                value={data.city}
                                onChange={(e) => setData('city', e.target.value)}
                                error={errors.city}
                            />
                            <Input
                                label="Postal Code"
                                value={data.postal_code}
                                onChange={(e) => setData('postal_code', e.target.value)}
                                error={errors.postal_code}
                            />
                        </div>
                        <div className="flex gap-6">
                            <Switch
                                label="Installation Point"
                                checked={data.is_installation_point}
                                onCheckedChange={(c) => setData('is_installation_point', c)}
                            />
                            <Switch
                                label="Primary"
                                checked={data.is_primary}
                                onCheckedChange={(c) => setData('is_primary', c)}
                            />
                        </div>
                        <Textarea
                            label="Notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            error={errors.notes}
                            rows={2}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setModalOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={processing}>
                                {editId ? 'Save' : 'Add'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
