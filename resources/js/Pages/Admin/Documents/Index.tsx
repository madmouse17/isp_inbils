import type { FormEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    StatusFilter,
    type DataTableColumn,
} from '@/Components/composite';
import { UsageLine } from '@/Components/composite/UsageLine';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button, Card, CardContent, EnumBadge, Input, Modal, Switch } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { LIFECYCLE_STATE, lifecycleStateOf } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { LifecycleFields } from '@/types/models';

type DtRow = LifecycleFields & {
    id: string;
    name: string;
    code: string;
    applies_to: string | null;
    is_required: boolean;
    expiry_days: number | null;
    is_active: boolean;
};

interface IndexProps extends Record<string, unknown> {
    documentTypes: Paginated<DtRow>;
    filters: {
        search?: string;
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { export: boolean; update: boolean; delete: boolean };
}

interface DocumentTypeForm {
    name: string;
    code: string;
    applies_to: string;
    is_required: boolean;
    expiry_days: string;
    is_active: boolean;
}

const emptyForm: DocumentTypeForm = {
    name: '',
    code: '',
    applies_to: '',
    is_required: false,
    expiry_days: '',
    is_active: true,
};

export default function Index({ documentTypes, filters, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<string | null>(null);
    const { data, setData, post, put, transform, processing, errors, reset, clearErrors } =
        useForm<DocumentTypeForm>(emptyForm);

    const {
        params,
        set,
        setMany,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.documents.index'),
        filters,
        only: ['documentTypes', 'filters', 'can'],
    });

    const openCreate = () => {
        reset();
        clearErrors();
        setEditId(null);
        setModalOpen(true);
    };

    const openEdit = useCallback(
        (type: DtRow) => {
            clearErrors();
            setData({
                name: type.name,
                code: type.code,
                applies_to: type.applies_to ?? '',
                is_required: type.is_required,
                expiry_days: type.expiry_days ? String(type.expiry_days) : '',
                is_active: type.is_active,
            });
            setEditId(type.id);
            setModalOpen(true);
        },
        [clearErrors, setData],
    );

    const close = () => {
        reset();
        clearErrors();
        setModalOpen(false);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // The code is fixed once the type exists: the server ignores it on update, so it is not sent.
        transform(({ code, ...values }) => (editId ? values : { code, ...values }));

        if (editId) {
            put(route('admin.documents.update', editId), { onSuccess: close });
        } else {
            post(route('admin.documents.store'), { onSuccess: close });
        }
    };

    const hasFilters = Boolean(params.search || params.status);

    const columns: DataTableColumn<DtRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (type) => (
                    <div className="space-y-0.5">
                        <p className={type.deleted_at ? 'text-muted-foreground' : undefined}>
                            {type.name}
                        </p>
                        <UsageLine references={type.references} />
                    </div>
                ),
            },
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                cell: (type) => <span className="font-mono text-sm">{type.code}</span>,
            },
            {
                key: 'applies_to',
                header: 'Berlaku untuk',
                sortable: true,
                sortKey: 'applies_to',
            },
            { key: 'is_required', header: 'Wajib' },
            {
                key: 'expiry_days',
                header: 'Masa berlaku',
                cell: (type) => (type.expiry_days ? `${type.expiry_days} hari` : '-'),
            },
            {
                key: 'status',
                header: 'Status',
                cell: (type) => <EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(type)} />,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (type) => (
                    <DataTableActions
                        onEdit={can.update ? () => openEdit(type) : undefined}
                        lifecycle={{
                            row: type,
                            name: type.name,
                            noun: 'Jenis dokumen',
                            routeBase: 'admin.documents',
                            can: { update: can.update, delete: can.delete },
                        }}
                    />
                ),
            },
        ],
        [openEdit, can.update, can.delete],
    );

    return (
        <AdminLayout title="Dokumen">
            <div className="space-y-6">
                <PageHeader
                    title="Dokumen"
                    subtitle="Jenis dokumen beserta status wajib dan masa berlakunya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.documents.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button type="button" variant="primary" onClick={openCreate}>
                                Buat Jenis Dokumen
                            </Button>
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-56 flex-1 sm:max-w-md">
                                <Input
                                    label="Cari"
                                    value={params.search ?? ''}
                                    onChange={(event) => set('search', event.target.value)}
                                    placeholder="Nama, kode, atau berlaku untuk"
                                />
                            </div>
                            <StatusFilter
                                id="document-status"
                                value={params.status ?? ''}
                                onChange={(value) => set('status', value)}
                                deactivatable
                            />
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(documentTypes)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada jenis dokumen"
                            emptyDescription="Jenis dokumen menentukan berkas apa yang wajib dan berapa lama berlaku. Buat jenis pertama untuk memulai."
                            emptyAction={
                                <Button type="button" variant="primary" onClick={openCreate}>
                                    Buat Jenis Dokumen
                                </Button>
                            }
                            hasFilters={hasFilters}
                            onResetFilters={() => setMany({ search: '', status: '' })}
                            itemLabel="jenis dokumen"
                            loading={tableProcessing}
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={close}
                    title={editId ? 'Ubah Jenis Dokumen' : 'Buat Jenis Dokumen'}
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
                            {editId ? (
                                <Input
                                    label="Kode"
                                    value={data.code}
                                    readOnly
                                    aria-readonly="true"
                                    hint="Kode tidak bisa diubah setelah jenis dokumen dibuat."
                                    className="bg-muted/50 text-muted-foreground"
                                />
                            ) : (
                                <Input
                                    label="Kode"
                                    value={data.code}
                                    onChange={(event) => setData('code', event.target.value)}
                                    error={errors.code}
                                    hint="Unik di perusahaan ini dan tidak bisa diubah nanti."
                                    required
                                />
                            )}
                            <Input
                                label="Berlaku untuk"
                                value={data.applies_to}
                                onChange={(event) => setData('applies_to', event.target.value)}
                                error={errors.applies_to}
                                placeholder="Mis. pelanggan"
                            />
                            <Input
                                label="Masa berlaku (hari)"
                                type="number"
                                min={1}
                                value={data.expiry_days}
                                onChange={(event) => setData('expiry_days', event.target.value)}
                                error={errors.expiry_days}
                            />
                        </div>
                        <div className="flex gap-6">
                            <Switch
                                label="Wajib"
                                checked={data.is_required}
                                onCheckedChange={(checked) => setData('is_required', checked)}
                            />
                            <Switch
                                label="Aktif"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={close}>
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
