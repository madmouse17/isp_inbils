import type { FormEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTableActions } from '@/Components/composite/DataTableActions';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import {
    Alert,
    AlertDescription,
    Badge,
    Button,
    Card,
    CardContent,
    EnumBadge,
    Input,
    Modal,
    NativeSelect,
    SearchSelect,
    Switch,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { ACTIVE_STATE, ORGANIZATION_TYPE, labelOf, optionsOf } from '@/lib/statuses';
import type { PageProps } from '@/types';

interface OrgRow {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    type: string;
    path?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    is_active: boolean;
    children_count?: number;
}

interface IndexProps extends Record<string, unknown> {
    organizations: {
        data: OrgRow[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
    };
    parentOptions: { data: OrgRow[] };
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { export: boolean };
}

interface OrganizationForm {
    code: string;
    name: string;
    type: string;
    parent_id: string;
    address: string;
    phone: string;
    email: string;
    is_active: boolean;
}

const emptyForm: OrganizationForm = {
    code: '',
    name: '',
    type: 'branch',
    parent_id: '',
    address: '',
    phone: '',
    email: '',
    is_active: true,
};

export default function Index({ organizations, parentOptions, filters, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm<OrganizationForm>(emptyForm);
    // A refused delete comes back as a validation error on the page, not on this form.
    const deleteError = (usePage<PageProps>().props.errors as Record<string, string | undefined>)
        .organization;

    const {
        params,
        set,
        setMany,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.organizations.index'),
        filters,
        only: ['organizations', 'parentOptions', 'filters', 'can'],
    });

    const rowMap = useMemo(
        () => new Map(parentOptions.data.map((organization) => [organization.id, organization])),
        [parentOptions.data],
    );
    const parentSelectOptions = useMemo(
        () =>
            parentOptions.data
                .filter((organization) => organization.id !== editId)
                .map((organization) => ({
                    value: String(organization.id),
                    label: `${organization.code} - ${organization.name}`,
                    description: organization.path ?? labelOf(ORGANIZATION_TYPE, organization.type),
                })),
        [parentOptions.data, editId],
    );

    const openCreate = () => {
        reset();
        clearErrors();
        setEditId(null);
        setModalOpen(true);
    };

    const openEdit = useCallback(
        (o: OrgRow) => {
            clearErrors();
            setData({
                code: o.code,
                name: o.name,
                type: o.type,
                parent_id: o.parent_id ? String(o.parent_id) : '',
                address: o.address ?? '',
                phone: o.phone ?? '',
                email: o.email ?? '',
                is_active: o.is_active,
            });
            setEditId(o.id);
            setModalOpen(true);
        },
        [clearErrors, setData],
    );

    const close = () => {
        reset();
        setModalOpen(false);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editId) {
            put(route('admin.organizations.update', editId), { onSuccess: close });
            return;
        }

        post(route('admin.organizations.store'), { onSuccess: close });
    };

    const hasFilters = Boolean(params.search);

    const columns: DataTableColumn<OrgRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                cell: (o) => <span className="font-mono text-sm">{o.code}</span>,
            },
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (o) => (
                    <div>
                        <p className="font-medium text-foreground">{o.name}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {o.parent_id
                                ? `Induk: ${rowMap.get(o.parent_id)?.name ?? '-'}`
                                : 'Unit utama'}
                        </p>
                    </div>
                ),
            },
            {
                key: 'type',
                header: 'Jenis',
                sortable: true,
                sortKey: 'type',
                cell: (o) => <EnumBadge map={ORGANIZATION_TYPE} value={o.type} />,
            },
            {
                key: 'path',
                header: 'Jalur',
                cell: (o) => <span className="text-sm text-muted-foreground">{o.path ?? '-'}</span>,
            },
            {
                key: 'children_count',
                header: 'Sub-unit',
                cell: (o) => <Badge variant="neutral">{o.children_count ?? 0} sub-unit</Badge>,
            },
            {
                key: 'is_active',
                header: 'Status',
                cell: (o) => <EnumBadge map={ACTIVE_STATE} value={o.is_active} />,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (o) => (
                    <DataTableActions
                        onEdit={() => openEdit(o)}
                        deleteHref={route('admin.organizations.destroy', o.id)}
                        deleteMessage={`Hapus ${o.name}?`}
                        deleteDescription={`Unit "${o.name}" akan dihapus. Unit yang masih punya sub-unit atau karyawan tidak bisa dihapus.`}
                    />
                ),
            },
        ],
        [rowMap, openEdit],
    );

    return (
        <AdminLayout title="Organisasi">
            <div className="space-y-6">
                <PageHeader
                    title="Organisasi"
                    subtitle="Susunan cabang, area, unit, dan tim perusahaan."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.organizations.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button type="button" variant="primary" onClick={openCreate}>
                                Buat Unit
                            </Button>
                        </div>
                    }
                />

                {deleteError ? (
                    <Alert variant="danger">
                        <AlertDescription>{deleteError}</AlertDescription>
                    </Alert>
                ) : null}

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="max-w-md">
                            <Input
                                label="Cari"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Kode, nama, atau jalur"
                            />
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(organizations)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada unit organisasi"
                            emptyDescription="Unit organisasi mengelompokkan karyawan. Buat unit pertama untuk memulai."
                            emptyAction={
                                <Button type="button" variant="primary" onClick={openCreate}>
                                    Buat Unit
                                </Button>
                            }
                            hasFilters={hasFilters}
                            onResetFilters={() => setMany({ search: '' })}
                            itemLabel="unit organisasi"
                            loading={tableProcessing}
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Ubah Unit' : 'Buat Unit'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Kode"
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value)}
                                error={errors.code}
                                required
                            />
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                error={errors.name}
                                required
                            />
                            <NativeSelect
                                label="Jenis"
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value)}
                                options={optionsOf(ORGANIZATION_TYPE)}
                                error={errors.type}
                                required
                            />
                            <SearchSelect
                                label="Induk"
                                value={data.parent_id}
                                onChange={(value) => setData('parent_id', value)}
                                options={parentSelectOptions}
                                placeholder={
                                    parentSelectOptions.length
                                        ? 'Cari unit induk'
                                        : 'Belum ada unit lain'
                                }
                                emptyText="Unit tidak ditemukan."
                                hint="Kosongkan untuk unit utama."
                                error={errors.parent_id}
                                disabled={parentSelectOptions.length === 0}
                            />
                            <Input
                                label="Telepon"
                                value={data.phone}
                                onChange={(event) => setData('phone', event.target.value)}
                                error={errors.phone}
                            />
                            <Input
                                label="Email"
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                error={errors.email}
                            />
                        </div>
                        <Input
                            label="Alamat"
                            value={data.address}
                            onChange={(event) => setData('address', event.target.value)}
                            error={errors.address}
                        />
                        <Switch
                            label="Aktif"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked)}
                        />
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
