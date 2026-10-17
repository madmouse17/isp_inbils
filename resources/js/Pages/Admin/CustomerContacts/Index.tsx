import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTableActions, PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import {
    Badge,
    Button,
    EmptyCell,
    Input,
    Modal,
    PhoneInput,
    Switch,
    Textarea,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';

interface ContactRow {
    id: string;
    name: string;
    position?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
}

interface IndexProps extends Record<string, unknown> {
    customer: { id: string; code: string; name: string };
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
    const [editingId, setEditingId] = useState<string | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm(formDefaults);

    const {
        params,
        search,
        setSearch,
        set,
        setMany,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
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

    const columns: DataTableColumn<ContactRow>[] = [
        {
            key: 'name',
            header: 'Nama',
            sortable: true,
            sortKey: 'name',
            cell: (row) => <span className="font-medium text-foreground">{row.name}</span>,
        },
        {
            key: 'position',
            header: 'Jabatan',
            sortable: true,
            sortKey: 'role',
            cell: (row) => row.position || <EmptyCell />,
        },
        {
            key: 'phone',
            header: 'Telepon',
            sortable: true,
            sortKey: 'phone',
            cell: (row) =>
                row.phone ? <span className="whitespace-nowrap">{row.phone}</span> : <EmptyCell />,
        },
        {
            key: 'email',
            header: 'Email',
            sortable: true,
            sortKey: 'email',
            cell: (row) => row.email || <EmptyCell />,
        },
        {
            key: 'is_primary',
            header: 'Utama',
            cell: (row) => (row.is_primary ? <Badge variant="brand">Utama</Badge> : <EmptyCell />),
        },
        {
            key: 'notes',
            header: 'Catatan',
            cell: (row) =>
                row.notes ? (
                    <span className="line-clamp-2 text-sm text-muted-foreground">{row.notes}</span>
                ) : (
                    <EmptyCell />
                ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (row) => (
                <DataTableActions
                    onEdit={() => openEdit(row)}
                    deleteHref={route('admin.customers.contacts.destroy', [customer.id, row.id])}
                    deleteMessage={`Hapus ${row.name}?`}
                    deleteDescription={`Kontak "${row.name}" akan dihapus permanen dari pelanggan ini.`}
                />
            ),
        },
    ];

    const addButton = (
        <Button type="button" variant="primary" size="sm" onClick={openCreate}>
            Tambah kontak
        </Button>
    );

    return (
        <AdminLayout title="Kontak Pelanggan">
            <div className="space-y-6">
                <PageHeader
                    title="Kontak"
                    subtitle={`${customer.code} - ${customer.name}`}
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
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.customers.show', customer.id)}>
                                    Kembali
                                </Link>
                            </Button>
                            {addButton}
                        </div>
                    }
                />

                <div className="space-y-4">
                    <div className="max-w-sm">
                        <Input
                            id="contacts-search"
                            label="Cari"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nama, jabatan, telepon, atau email"
                        />
                    </div>

                    <DataTable
                        columns={columns}
                        pagination={toPagination(contacts)}
                        sortBy={sortBy}
                        sortDir={sortDir}
                        onSort={onSort}
                        onPageChange={(page) => set('page', page)}
                        loading={tableProcessing}
                        itemLabel="kontak"
                        hasFilters={Boolean(params.search)}
                        onResetFilters={() => setMany({ search: '' })}
                        emptyTitle="Belum ada kontak"
                        emptyDescription="Catat narahubung pelanggan ini, termasuk satu kontak utama."
                        emptyAction={addButton}
                    />
                </div>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editingId ? 'Ubah Kontak' : 'Tambah Kontak'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                error={errors.name}
                                required
                            />
                            <Input
                                label="Jabatan"
                                value={data.position}
                                onChange={(event) => setData('position', event.target.value)}
                                error={errors.position}
                            />
                            <PhoneInput
                                label="Telepon"
                                value={data.phone}
                                onChange={(value) => setData('phone', value)}
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
                            label="Catatan"
                            value={data.notes}
                            onChange={(event) => setData('notes', event.target.value)}
                            error={errors.notes}
                            rows={3}
                        />
                        <Switch
                            label="Kontak utama"
                            checked={data.is_primary}
                            onCheckedChange={(checked) => setData('is_primary', checked)}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setModalOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                {editingId ? 'Simpan' : 'Tambah'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
