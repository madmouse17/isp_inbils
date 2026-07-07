import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Button, Card, CardContent, Input, Select, Table, TBody, TD, TH, THead, TR, Modal } from '@/Components/ui';

interface EmpRow {
    id: number; employee_number: string; status: string; phone?: string | null;
    user?: { name: string; email: string } | null;
    organization?: { name: string } | null;
    vehicle?: { plate_number: string } | null;
}

interface OrgRow { id: number; name: string }
interface VehRow { id: number; plate_number: string }
interface UserRow { id: number; name: string }

interface IndexProps extends Record<string, unknown> {
    employees: { data: EmpRow[]; current_page: number; last_page: number };
    organizations: { data: OrgRow[] };
    vehicles: { data: VehRow[] };
    users: { data: UserRow[] };
}

export default function Index({ employees, organizations, vehicles, users }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        user_id: '', organization_id: '', vehicle_id: '', employee_number: '', phone: '', hire_date: '', status: 'active', notes: '',
    });

    const openCreate = () => { reset(); setEditId(null); setModalOpen(true); };
    const openEdit = (e: EmpRow) => { setEditId(e.id); setModalOpen(true); };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) { put(route('admin.employees.update', editId), { onSuccess: () => setModalOpen(false) }); }
        else { post(route('admin.employees.store'), { onSuccess: () => setModalOpen(false) }); }
    };

    return (
        <AdminLayout title="Employees">
            <div className="space-y-6">
                <PageHeader title="Employee Profiles" subtitle="Manage employee profiles linked to users." actions={<Button type="button" onClick={openCreate}>Add Employee</Button>} />
                <Card><CardContent className="space-y-4 pt-6">
                    <Table>
                        <THead><TR><TH>Emp No</TH><TH>Name</TH><TH>Organization</TH><TH>Vehicle</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                        <TBody>
                            {employees.data.length === 0 ? (
                                <TR><TD colSpan={6} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                            ) : employees.data.map((e) => (
                                <TR key={e.id}>
                                    <TD className="font-mono text-sm">{e.employee_number}</TD>
                                    <TD>{e.user?.name ?? '-'}</TD>
                                    <TD>{e.organization?.name ?? '-'}</TD>
                                    <TD>{e.vehicle?.plate_number ?? '-'}</TD>
                                    <TD><Badge variant={e.status === 'active' ? 'success' : 'danger'}>{e.status}</Badge></TD>
                                    <TD><Button type="button" variant="ghost" size="sm" onClick={() => openEdit(e)}>Edit</Button></TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </CardContent></Card>
                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editId ? 'Edit Employee' : 'Add Employee'}>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            {!editId && (
                                <Select label="User" value={data.user_id} onChange={(e) => setData('user_id', e.target.value)} error={errors.user_id} required>
                                    <option value="">Select...</option>
                                    {users.data.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </Select>
                            )}
                            <Input label="Employee Number" value={data.employee_number} onChange={(e) => setData('employee_number', e.target.value)} error={errors.employee_number} required />
                            <Select label="Organization" value={data.organization_id} onChange={(e) => setData('organization_id', e.target.value)} error={errors.organization_id}>
                                <option value="">None</option>
                                {organizations.data.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </Select>
                            <Select label="Vehicle" value={data.vehicle_id} onChange={(e) => setData('vehicle_id', e.target.value)} error={errors.vehicle_id}>
                                <option value="">None</option>
                                {vehicles.data.map((v) => <option key={v.id} value={v.id}>{v.plate_number}</option>)}
                            </Select>
                            <Input label="Phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                            <Input label="Hire Date" type="date" value={data.hire_date} onChange={(e) => setData('hire_date', e.target.value)} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                            <Button type="submit" loading={processing}>{editId ? 'Save' : 'Add'}</Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
