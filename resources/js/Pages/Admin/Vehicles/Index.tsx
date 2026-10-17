import type { FormEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import {
    Button,
    Card,
    CardContent,
    DatePicker,
    EnumBadge,
    Input,
    Modal,
    Switch,
    Textarea,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { ACTIVE_STATE } from '@/lib/statuses';
import type { Paginated } from '@/types';

interface VehRow {
    id: string;
    plate_number: string;
    type: string | null;
    brand: string | null;
    model: string | null;
    purchase_date: string | null;
    is_active: boolean;
    notes: string | null;
}

interface IndexProps extends Record<string, unknown> {
    vehicles: Paginated<VehRow>;
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { create: boolean; export: boolean };
}

interface VehicleForm {
    plate_number: string;
    type: string;
    brand: string;
    model: string;
    purchase_date: string;
    is_active: boolean;
    notes: string;
}

const emptyForm: VehicleForm = {
    plate_number: '',
    type: '',
    brand: '',
    model: '',
    purchase_date: '',
    is_active: true,
    notes: '',
};

export default function Index({ vehicles, filters, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<string | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm<VehicleForm>(emptyForm);

    const {
        params,
        set,
        setMany,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.vehicles.index'),
        filters,
        only: ['vehicles', 'filters', 'can'],
    });

    const openCreate = () => {
        reset();
        clearErrors();
        setEditId(null);
        setModalOpen(true);
    };

    const openEdit = useCallback(
        (vehicle: VehRow) => {
            clearErrors();
            setData({
                plate_number: vehicle.plate_number,
                type: vehicle.type ?? '',
                brand: vehicle.brand ?? '',
                model: vehicle.model ?? '',
                purchase_date: vehicle.purchase_date ?? '',
                is_active: vehicle.is_active,
                notes: vehicle.notes ?? '',
            });
            setEditId(vehicle.id);
            setModalOpen(true);
        },
        [clearErrors, setData],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const onSuccess = () => setModalOpen(false);

        if (editId) {
            put(route('admin.vehicles.update', editId), { onSuccess });
        } else {
            post(route('admin.vehicles.store'), { onSuccess });
        }
    };

    const hasFilters = Boolean(params.search);

    const columns: DataTableColumn<VehRow>[] = useMemo(() => {
        const base: DataTableColumn<VehRow>[] = [
            {
                key: 'plate_number',
                header: 'Nomor polisi',
                sortable: true,
                sortKey: 'plate_number',
                cell: (vehicle) => (
                    <span className="font-mono text-sm">{vehicle.plate_number}</span>
                ),
            },
            { key: 'type', header: 'Jenis', sortable: true, sortKey: 'type' },
            { key: 'brand', header: 'Merek', sortable: true, sortKey: 'brand' },
            { key: 'model', header: 'Model', sortable: true, sortKey: 'model' },
            {
                key: 'is_active',
                header: 'Status',
                cell: (vehicle) => <EnumBadge map={ACTIVE_STATE} value={vehicle.is_active} />,
            },
        ];

        if (!can.create) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Aksi',
                cell: (vehicle) => (
                    <DataTableActions
                        onEdit={() => openEdit(vehicle)}
                        deleteHref={route('admin.vehicles.destroy', vehicle.id)}
                        deleteMessage={`Hapus ${vehicle.plate_number}?`}
                        deleteDescription={`Kendaraan "${vehicle.plate_number}" akan dihapus dari daftar.`}
                    />
                ),
            },
        ];
    }, [can.create, openEdit]);

    return (
        <AdminLayout title="Kendaraan">
            <div className="space-y-6">
                <PageHeader
                    title="Kendaraan"
                    subtitle="Kendaraan operasional yang dipakai teknisi dan karyawan."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.vehicles.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button type="button" variant="primary" onClick={openCreate}>
                                    Buat Kendaraan
                                </Button>
                            ) : null}
                        </div>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="max-w-md">
                            <Input
                                label="Cari"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Nomor polisi, jenis, merek, atau model"
                            />
                        </div>
                        <DataTable
                            columns={columns}
                            pagination={toPagination(vehicles)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada kendaraan"
                            emptyDescription="Kendaraan yang sudah dicatat bisa dipasangkan ke karyawan. Buat kendaraan pertama untuk memulai."
                            emptyAction={
                                can.create ? (
                                    <Button type="button" variant="primary" onClick={openCreate}>
                                        Buat Kendaraan
                                    </Button>
                                ) : undefined
                            }
                            hasFilters={hasFilters}
                            onResetFilters={() => setMany({ search: '' })}
                            itemLabel="kendaraan"
                            loading={tableProcessing}
                        />
                    </CardContent>
                </Card>
                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Ubah Kendaraan' : 'Buat Kendaraan'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Nomor polisi"
                                value={data.plate_number}
                                onChange={(event) => setData('plate_number', event.target.value)}
                                error={errors.plate_number}
                                required
                            />
                            <Input
                                label="Jenis"
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value)}
                                error={errors.type}
                                placeholder="Motor, mobil, atau pikap"
                            />
                            <Input
                                label="Merek"
                                value={data.brand}
                                onChange={(event) => setData('brand', event.target.value)}
                                error={errors.brand}
                            />
                            <Input
                                label="Model"
                                value={data.model}
                                onChange={(event) => setData('model', event.target.value)}
                                error={errors.model}
                            />
                            <DatePicker
                                label="Tanggal pembelian"
                                value={data.purchase_date}
                                onChange={(value) => setData('purchase_date', value)}
                                error={errors.purchase_date}
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Aktif"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked)}
                                />
                            </div>
                        </div>
                        <Textarea
                            label="Catatan"
                            value={data.notes}
                            onChange={(event) => setData('notes', event.target.value)}
                            error={errors.notes}
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
                                {editId ? 'Simpan' : 'Buat'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
