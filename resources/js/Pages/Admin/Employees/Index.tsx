import type { FormEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    ConflictBanner,
    DataTable,
    DataTableActions,
    PageHeader,
    RemoteSearchSelect,
    SequenceCodeField,
    StatusFilter,
    type CodeSequence,
    type DataTableColumn,
    type RemoteOption,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { EMPLOYEE_STATUS, LIFECYCLE_STATE, optionsOf } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { LifecycleFields } from '@/types/models';
import {
    Button,
    Card,
    CardContent,
    DatePicker,
    EnumBadge,
    Input,
    Modal,
    NativeSelect,
    SearchSelect,
    Textarea,
} from '@/Components/ui';

interface EmpRow extends LifecycleFields {
    updated_at: string;
    id: string;
    user_id: string;
    organization_id: string | null;
    vehicle_id: string | null;
    employee_number: string;
    status: string;
    phone?: string | null;
    hire_date?: string | null;
    notes?: string | null;
    user?: { name: string; email: string } | null;
    organization?: { name: string } | null;
    vehicle?: { plate_number: string } | null;
}

interface OrgRow {
    id: string;
    code: string;
    name: string;
    path?: string | null;
}

interface VehRow {
    id: string;
    plate_number: string;
    type?: string | null;
    brand?: string | null;
    model?: string | null;
}

interface IndexProps extends Record<string, unknown> {
    employees: Paginated<EmpRow>;
    organizations: { data: OrgRow[] };
    vehicles: { data: VehRow[] };
    userOptions?: RemoteOption[];
    filters: {
        search?: string;
        organization_id?: string;
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean; update: boolean; delete: boolean };
    codeSequence: CodeSequence;
}

interface EmployeeForm {
    user_id: string;
    organization_id: string;
    vehicle_id: string;
    employee_number: string;
    phone: string;
    hire_date: string;
    status: string;
    notes: string;
    /** Row version the edit started from; blank on create, where the server ignores it. */
    expected_updated_at: string;
}

const emptyForm: EmployeeForm = {
    user_id: '',
    organization_id: '',
    vehicle_id: '',
    employee_number: '',
    phone: '',
    hire_date: '',
    status: 'active',
    notes: '',
    expected_updated_at: '',
};

export default function Index({
    employees,
    organizations,
    vehicles,
    userOptions,
    filters,
    can,
    codeSequence,
}: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<EmpRow | null>(null);
    const {
        params,
        set,
        setMany,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.employees.index'),
        filters,
        only: ['employees', 'organizations', 'vehicles', 'filters', 'can', 'codeSequence'],
    });
    const { data, setData, post, put, transform, processing, errors, reset, clearErrors } =
        useForm<EmployeeForm>(emptyForm);

    const organizationOptions = useMemo(
        () =>
            organizations.data.map((organization) => ({
                value: String(organization.id),
                label: `${organization.code} - ${organization.name}`,
                description: organization.path ?? undefined,
            })),
        [organizations.data],
    );
    const vehicleOptions = useMemo(
        () =>
            vehicles.data.map((vehicle) => ({
                value: String(vehicle.id),
                label: vehicle.plate_number,
                description:
                    [vehicle.type, vehicle.brand, vehicle.model].filter(Boolean).join(' ') ||
                    undefined,
            })),
        [vehicles.data],
    );

    const openCreate = () => {
        reset();
        clearErrors();
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = useCallback(
        (employee: EmpRow) => {
            clearErrors();
            setData({
                user_id: employee.user_id,
                organization_id: employee.organization_id ?? '',
                vehicle_id: employee.vehicle_id ?? '',
                employee_number: employee.employee_number,
                phone: employee.phone ?? '',
                hire_date: employee.hire_date ?? '',
                status: employee.status,
                notes: employee.notes ?? '',
                expected_updated_at: employee.updated_at,
            });
            setEditing(employee);
            setModalOpen(true);
        },
        [clearErrors, setData],
    );
    const close = () => {
        reset();
        clearErrors();
        setModalOpen(false);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        transform(({ user_id, employee_number, expected_updated_at, ...values }) => ({
            ...values,
            // The user is picked once, on create. A locked number is generated on create and never
            // changes on update, so it is not sent either.
            ...(editing ? { expected_updated_at } : { user_id }),
            ...(codeSequence.manual ? { employee_number } : {}),
        }));
        if (editing) {
            put(route('admin.employees.update', editing.id), { onSuccess: close });
        } else {
            post(route('admin.employees.store'), { onSuccess: close });
        }
    };

    const hasFilters = Boolean(params.search || params.organization_id || params.status);

    const columns: DataTableColumn<EmpRow>[] = useMemo(
        () => [
            {
                key: 'employee_number',
                header: 'Nomor karyawan',
                sortable: true,
                sortKey: 'employee_number',
                cell: (employee) => (
                    <span
                        className={
                            employee.deleted_at
                                ? 'font-mono text-sm text-muted-foreground'
                                : 'font-mono text-sm'
                        }
                    >
                        {employee.employee_number}
                    </span>
                ),
            },
            {
                key: 'name',
                header: 'Nama',
                cell: (employee) => (
                    <div>
                        <p
                            className={
                                employee.deleted_at
                                    ? 'font-medium text-muted-foreground'
                                    : 'font-medium text-foreground'
                            }
                        >
                            {employee.user?.name ?? '-'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {employee.user?.email ?? '-'}
                        </p>
                    </div>
                ),
            },
            {
                key: 'organization',
                header: 'Organisasi',
                cell: (employee) => employee.organization?.name ?? '-',
            },
            {
                key: 'vehicle',
                header: 'Kendaraan',
                cell: (employee) => employee.vehicle?.plate_number ?? '-',
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (employee) =>
                    employee.deleted_at ? (
                        <EnumBadge map={LIFECYCLE_STATE} value="dihapus" />
                    ) : (
                        <EnumBadge map={EMPLOYEE_STATUS} value={employee.status} />
                    ),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (employee) => (
                    <DataTableActions
                        onEdit={can.update ? () => openEdit(employee) : undefined}
                        lifecycle={{
                            row: employee,
                            name: employee.employee_number,
                            noun: 'Karyawan',
                            routeBase: 'admin.employees',
                            can: { update: can.update, delete: can.delete },
                        }}
                    />
                ),
            },
        ],
        [can.update, can.delete, openEdit],
    );

    return (
        <AdminLayout title="Karyawan">
            <div className="space-y-6">
                <PageHeader
                    title="Karyawan"
                    subtitle="Profil karyawan beserta pengguna, organisasi, dan kendaraannya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.employees.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button type="button" variant="primary" onClick={openCreate}>
                                    Buat Karyawan
                                </Button>
                            ) : null}
                        </div>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="grid gap-3 md:grid-cols-3">
                            <Input
                                label="Cari"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Nomor, nama, email, atau telepon"
                            />
                            <SearchSelect
                                label="Organisasi"
                                value={params.organization_id ?? ''}
                                onChange={(value) => set('organization_id', value)}
                                options={organizationOptions}
                                placeholder="Semua organisasi"
                                emptyText="Organisasi tidak ditemukan."
                            />
                            <StatusFilter
                                id="employee-status"
                                value={params.status ?? ''}
                                onChange={(value) => set('status', value)}
                                statuses={EMPLOYEE_STATUS}
                                wrapperClassName="w-full"
                            />
                        </div>
                        <DataTable
                            columns={columns}
                            pagination={toPagination(employees)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada karyawan"
                            emptyDescription="Profil karyawan menghubungkan pengguna dengan organisasi dan kendaraan. Buat profil pertama untuk memulai."
                            emptyAction={
                                can.create ? (
                                    <Button type="button" variant="primary" onClick={openCreate}>
                                        Buat Karyawan
                                    </Button>
                                ) : undefined
                            }
                            hasFilters={hasFilters}
                            onResetFilters={() =>
                                setMany({ search: '', organization_id: '', status: '' })
                            }
                            itemLabel="karyawan"
                            loading={tableProcessing}
                        />
                    </CardContent>
                </Card>
                <Modal
                    open={modalOpen}
                    onClose={close}
                    title={editing ? 'Ubah Karyawan' : 'Buat Karyawan'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        {editing ? <ConflictBanner /> : null}
                        <div className="grid gap-4 md:grid-cols-2">
                            {editing ? (
                                <Input
                                    label="Pengguna"
                                    value={
                                        editing.user
                                            ? `${editing.user.name} (${editing.user.email})`
                                            : ''
                                    }
                                    readOnly
                                    aria-readonly="true"
                                    hint="Pengguna tidak bisa diganti."
                                    className="bg-muted/50 text-muted-foreground"
                                />
                            ) : (
                                <RemoteSearchSelect
                                    field="user"
                                    label="Pengguna"
                                    options={userOptions}
                                    value={data.user_id}
                                    onChange={(value) => setData('user_id', value)}
                                    placeholder="Cari nama atau email"
                                    emptyText="Tidak ada pengguna aktif yang cocok dan belum punya profil."
                                    hint="Hanya pengguna aktif yang belum punya profil karyawan."
                                    error={errors.user_id}
                                    required
                                />
                            )}
                            <SequenceCodeField
                                label="Nomor karyawan"
                                value={data.employee_number}
                                onChange={(next) => setData('employee_number', next)}
                                error={errors.employee_number}
                                sequence={codeSequence}
                                mode={editing ? 'edit' : 'create'}
                            />
                            <SearchSelect
                                label="Organisasi"
                                value={data.organization_id}
                                onChange={(value) => setData('organization_id', value)}
                                options={organizationOptions}
                                placeholder="Cari organisasi"
                                emptyText="Organisasi tidak ditemukan."
                                error={errors.organization_id}
                            />
                            <SearchSelect
                                label="Kendaraan"
                                value={data.vehicle_id}
                                onChange={(value) => setData('vehicle_id', value)}
                                options={vehicleOptions}
                                placeholder="Cari nomor polisi"
                                emptyText="Kendaraan tidak ditemukan."
                                error={errors.vehicle_id}
                            />
                            <Input
                                label="Telepon"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                error={errors.phone}
                            />
                            <DatePicker
                                label="Tanggal masuk"
                                value={data.hire_date}
                                onChange={(value) => setData('hire_date', value)}
                                error={errors.hire_date}
                            />
                            <NativeSelect
                                label="Status"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                                options={optionsOf(EMPLOYEE_STATUS)}
                                error={errors.status}
                            />
                        </div>
                        <Textarea
                            label="Catatan"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            error={errors.notes}
                        />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={close}>
                                Batal
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                {editing ? 'Simpan' : 'Buat'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
