import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import { Badge, Button, Card, CardContent, Input, Modal, Switch, Textarea } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';

interface ContactRow {
    id: number;
    name: string;
    position?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
}

interface IndexProps extends Record<string, unknown> {
    customer: { id: number; code: string; name: string };
    contacts: {
        data: ContactRow[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
    };
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { export: boolean };
}

const formDefaults = {
    name: '',
    position: '',
    phone: '',
    email: '',
    is_primary: false,
    notes: '',
};

export default function Index({ customer, contacts, filters, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm(formDefaults);

    const { params, set, sortBy, sortDir, onSort } = useServerTable({
        url: route('admin.customers.contacts.index', customer.id),
        filters,
        only: ['contacts', 'filters', 'can', 'customer'],
    });

    const openCreate = () => {
        reset();
        setEditingId(null);
        setModalOpen(true);
    };

    const openEdit = (row: ContactRow) => {
        setData('name', row.name);
        setData('position', row.position ?? '');
        setData('phone', row.phone ?? '');
        setData('email', row.email ?? '');
        setData('is_primary', row.is_primary);
        setData('notes', row.notes ?? '');
        setEditingId(row.id);
        setModalOpen(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editingId) {
            put(route('admin.customers.contacts.update', [customer.id, editingId]), {
                onSuccess: () => {
                    reset();
                    setModalOpen(false);
                },
            });
            return;
        }

        post(route('admin.customers.contacts.store', customer.id), {
            onSuccess: () => {
                reset();
                setModalOpen(false);
            },
        });
    };

    const remove = (row: ContactRow) => {
        if (window.confirm(`Delete ${row.name}?`)) {
            router.delete(route('admin.customers.contacts.destroy', [customer.id, row.id]), {
                preserveScroll: true,
            });
        }
    };

    const columns: DataTableColumn<ContactRow>[] = [
        {
            key: 'name',
            header: 'Name',
            sortable: true,
            sortKey: 'name',
            cell: (row) => <span className="font-medium text-foreground">{row.name}</span>,
        },
        {
            key: 'position',
            header: 'Position',
            sortable: true,
            sortKey: 'role',
            cell: (row) => row.position ?? '—',
        },
        {
            key: 'phone',
            header: 'Phone',
            sortable: true,
            sortKey: 'phone',
            cell: (row) => row.phone ?? '—',
        },
        {
            key: 'email',
            header: 'Email',
            sortable: true,
            sortKey: 'email',
            cell: (row) => row.email ?? '—',
        },
        {
            key: 'is_primary',
            header: 'Primary',
            cell: (row) => (
                <Badge variant={row.is_primary ? 'brand' : 'neutral'}>
                    {row.is_primary ? 'Primary' : 'Secondary'}
                </Badge>
            ),
        },
        {
            key: 'notes',
            header: 'Notes',
            cell: (row) => (
                <span className="line-clamp-2 text-sm text-muted-foreground">
                    {row.notes ?? '—'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            cell: (row) => (
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(row)}>
                        Edit
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => remove(row)}>
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="Customer Contacts">
            <div className="space-y-6">
                <PageHeader
                    title="Customer Contacts"
                    subtitle={`${customer.code} — ${customer.name}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route(
                                        'admin.customers.contacts.export',
                                        customer.id,
                                    )}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button type="button" onClick={openCreate}>
                                Add Contact
                            </Button>
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:items-end">
                            <div className="min-w-[12rem] flex-1 space-y-1">
                                <label
                                    className="text-xs font-medium text-muted-foreground"
                                    htmlFor="contacts-search"
                                >
                                    Search
                                </label>
                                <Input
                                    id="contacts-search"
                                    value={params.search ?? ''}
                                    onChange={(event) => set('search', event.target.value)}
                                    placeholder="Name, position, phone, email…"
                                />
                            </div>
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(contacts)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            emptyTitle="No contacts"
                            emptyDescription="No contacts match the current filters."
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editingId ? 'Edit Contact' : 'Add Contact'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                error={errors.name}
                                required
                            />
                            <Input
                                label="Position"
                                value={data.position}
                                onChange={(event) => setData('position', event.target.value)}
                                error={errors.position}
                            />
                            <Input
                                label="Phone"
                                value={data.phone}
                                onChange={(event) => setData('phone', event.target.value)}
                                error={errors.phone}
                            />
                            <Input
                                label="Email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                error={errors.email}
                            />
                        </div>
                        <Textarea
                            label="Notes"
                            value={data.notes}
                            onChange={(event) => setData('notes', event.target.value)}
                            error={errors.notes}
                            rows={3}
                        />
                        <Switch
                            label="Primary contact"
                            checked={data.is_primary}
                            onCheckedChange={(checked) => setData('is_primary', checked)}
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
                                {editingId ? 'Save' : 'Add'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
