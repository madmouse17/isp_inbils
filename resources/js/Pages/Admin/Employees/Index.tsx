import type { FormEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    type CodeSequence,
    type DataTableColumn,
    PageHeader,
    SequenceCodeField,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';
import { Badge, Button, Card, CardContent, Input, NativeSelect, Modal } from '@/Components/ui';

interface EmpRow {
    id: number;
    employee_number: string;
    status: string;
    phone?: string | null;
    user?: { name: string; email: string } | null;
    organization?: { name: string } | null;
    vehicle?: { plate_number: string } | null;
}

interface OrgRow {
    id: number;
    name: string;
}
interface VehRow {
    id: number;
    plate_number: string;
}
interface UserRow {
    id: number;
    name: string;
}

interface IndexProps extends Record<string, unknown> {
    employees: Paginated<EmpRow>;
    organizations: { data: OrgRow[] };
    vehicles: { data: VehRow[] };
    users: { data: UserRow[] };
    filters: {
        search?: string;
        organization_id?: string;
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
    codeSequence: CodeSequence;
}

export default function Index({
    employees,
    organizations,
    vehicles,
    users,
    filters,
    can,
    codeSequence,
}: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const {
        params,
        set,
        sortBy,
        sortDir,
        onSort,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.employees.index'),
        filters,
        only: ['employees', 'organizations', 'vehicles', 'users', 'filters', 'can', 'codeSequence'],
    });
    const { data, setData, post, put, transform, processing, errors, reset } = useForm({
        user_id: '',
        organization_id: '',
        vehicle_id: '',
        employee_number: '',
        phone: '',
        hire_date: '',
        status: 'active',
        notes: '',
    });

    const openCreate = () => {
        reset();
        setEditId(null);
        setModalOpen(true);
    };
    const openEdit = useCallback(
        (employee: EmpRow) => {
            reset();
            setData('employee_number', employee.employee_number);
            setEditId(employee.id);
            setModalOpen(true);
        },
        [reset, setData],
    );
    const submit = (e: FormEvent) => {
        e.preventDefault();
        // A locked number is generated on create and never changes on update, so it is not sent.
        if (!codeSequence.manual) transform(({ employee_number: _number, ...values }) => values);
        if (editId) {
            put(route('admin.employees.update', editId), { onSuccess: () => setModalOpen(false) });
        } else {
            post(route('admin.employees.store'), { onSuccess: () => setModalOpen(false) });
        }
    };

    const columns: DataTableColumn<EmpRow>[] = useMemo(
        () => [
            {
                key: 'employee_number',
                header: 'Emp No',
                sortable: true,
                sortKey: 'employee_number',
                cell: (employee) => (
                    <span className="font-mono text-sm">{employee.employee_number}</span>
                ),
            },
            { key: 'name', header: 'Name', cell: (employee) => employee.user?.name ?? '-' },
            {
                key: 'organization',
                header: 'Organization',
                cell: (employee) => employee.organization?.name ?? '-',
            },
            {
                key: 'vehicle',
                header: 'Vehicle',
                cell: (employee) => employee.vehicle?.plate_number ?? '-',
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (employee) => (
                    <Badge variant={employee.status === 'active' ? 'success' : 'danger'}>
                        {employee.status}
                    </Badge>
                ),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (employee) =>
                    can.create ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => openEdit(employee)}
                        >
                            Edit
                        </Button>
                    ) : null,
            },
        ],
        [can.create, openEdit],
    );

    return (
        <AdminLayout title="Karyawan">
            <div className="space-y-6">
                <PageHeader
                    title="Karyawan"
                    subtitle="Manage employee profiles linked to users."
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
                                    Add Employee
                                </Button>
                            ) : null}
                        </div>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="grid gap-3 md:grid-cols-3">
                            <Input
                                label="Search"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Employee number, name, email, phone"
                            />
                            <NativeSelect
                                label="Organization"
                                value={params.organization_id ?? ''}
                                onChange={(event) => set('organization_id', event.target.value)}
                            >
                                <option value="">All organizations</option>
                                {organizations.data.map((organization) => (
                                    <option key={organization.id} value={organization.id}>
                                        {organization.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                label="Status"
                                value={params.status ?? ''}
                                onChange={(event) => set('status', event.target.value)}
                                options={[
                                    { value: '', label: 'All statuses' },
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                    { value: 'terminated', label: 'Terminated' },
                                ]}
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
                            emptyTitle="No employees"
                            emptyDescription="No employees match the current filters."
                            loading={tableProcessing}
                        />
                    </CardContent>
                </Card>
                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Edit Employee' : 'Add Employee'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            {!editId && (
                                <NativeSelect
                                    label="User"
                                    value={data.user_id}
                                    onChange={(e) => setData('user_id', e.target.value)}
                                    error={errors.user_id}
                                    required
                                >
                                    <option value="">Select...</option>
                                    {users.data.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                            <SequenceCodeField
                                label="Employee Number"
                                value={data.employee_number}
                                onChange={(next) => setData('employee_number', next)}
                                error={errors.employee_number}
                                sequence={codeSequence}
                                mode={editId ? 'edit' : 'create'}
                            />
                            <NativeSelect
                                label="Organization"
                                value={data.organization_id}
                                onChange={(e) => setData('organization_id', e.target.value)}
                                error={errors.organization_id}
                            >
                                <option value="">None</option>
                                {organizations.data.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                label="Vehicle"
                                value={data.vehicle_id}
                                onChange={(e) => setData('vehicle_id', e.target.value)}
                                error={errors.vehicle_id}
                            >
                                <option value="">None</option>
                                {vehicles.data.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.plate_number}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                label="Phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                            />
                            <Input
                                label="Hire Date"
                                type="date"
                                value={data.hire_date}
                                onChange={(e) => setData('hire_date', e.target.value)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setModalOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                {editId ? 'Save' : 'Add'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
