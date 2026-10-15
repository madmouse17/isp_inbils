import type { FormEvent } from 'react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useServerTable } from '@/hooks/useServerTable';
import { type PaginationLike, toPagination } from '@/lib/pagination';
import { Button, Card, CardContent, Input, Modal } from '@/Components/ui';
import type { Unit } from '@/types/inventory';

interface IndexProps extends Record<string, unknown> {
    units: PaginationLike<Unit>;
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { create: boolean; export: boolean };
}

export default function Index({ units, filters, can }: IndexProps) {
    const table = useServerTable({
        url: route('admin.units.index'),
        filters,
        only: ['units', 'filters', 'can'],
    });
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Unit | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        symbol: '',
    });

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
            put(route('admin.units.update', editing.id), {
                onSuccess: () => {
                    setModalOpen(false);
                    reset();
                },
            });
        } else {
            post(route('admin.units.store'), {
                onSuccess: () => {
                    setModalOpen(false);
                    reset();
                },
            });
        }
    };

    const columns: DataTableColumn<Unit>[] = [
        { key: 'name', header: 'Nama', sortable: true, sortKey: 'name' },
        {
            key: 'symbol',
            header: 'Simbol',
            sortable: true,
            sortKey: 'symbol',
            cell: (u) => <span className="font-mono">{u.symbol}</span>,
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (u) => (
                <DataTableActions
                    onEdit={() => openEdit(u)}
                    deleteHref={route('admin.units.destroy', u.id)}
                    deleteMessage={`Hapus ${u.name}?`}
                    deleteDescription={`Satuan "${u.name}" akan dihapus dari daftar. Satuan yang masih dipakai barang atau kategori tidak bisa dihapus.`}
                />
            ),
        },
    ];

    const createButton = can.create ? (
        <Button type="button" variant="primary" onClick={openCreate}>
            Buat Satuan
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Satuan">
            <div className="space-y-6">
                <PageHeader
                    title="Satuan"
                    subtitle="Satuan ukur barang, misalnya meter atau buah."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.units.export')}
                                params={table.params}
                                formats={['csv', 'pdf']}
                                canExport={can.export}
                            />
                            {createButton}
                        </div>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <DataTable
                            columns={columns}
                            pagination={toPagination(units)}
                            sortBy={table.sortBy}
                            sortDir={table.sortDir}
                            onSort={table.onSort}
                            onPageChange={table.setPage}
                            onPerPageChange={table.setPerPage}
                            loading={table.processing}
                            itemLabel="satuan"
                            emptyDescription="Buat satuan lebih dulu, lalu pakai satuan itu pada kategori barang."
                            emptyAction={createButton}
                        />
                    </CardContent>
                </Card>
            </div>

            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? 'Ubah Satuan' : 'Buat Satuan'}
            >
                <form onSubmit={submitForm} className="space-y-4">
                    <Input
                        label="Nama"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                    <Input
                        label="Simbol"
                        value={data.symbol}
                        onChange={(e) => setData('symbol', e.target.value)}
                        error={errors.symbol}
                        required
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
                            {editing ? 'Simpan' : 'Buat'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
