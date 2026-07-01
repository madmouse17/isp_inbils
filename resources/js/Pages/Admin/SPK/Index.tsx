import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Select, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { StatusBadge } from '@/Components/composite';

interface WoRow {
    id: number; code: string; type: string; title: string; status: string; priority: string;
    customer?: { name: string } | null;
    assignee?: { name: string } | null;
}

interface TechRow { id: number; name: string }

interface IndexProps extends Record<string, unknown> {
    workOrders: { data: WoRow[]; current_page: number; last_page: number };
    technicians: { data: TechRow[] };
    filters: { type?: string; status?: string; assigned_to?: string; search?: string };
    can: { create: boolean };
}

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'completed' ? 'success' : s === 'in_progress' || s === 'waiting_review' ? 'info' : s === 'rejected' ? 'danger' : s === 'cancelled' ? 'muted' : 'warning';

export default function Index({ workOrders, technicians, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [assignedTo, setAssignedTo] = useState(filters.assigned_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.spk.index'), { search, type, status, assigned_to: assignedTo }, { preserveState: true });
    };

    return (
        <AdminLayout title="SPK">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Surat Perintah Kerja</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Work orders for field technicians.</p>
                    </div>
                    {can.create && <Button type="button" onClick={() => router.get(route('admin.spk.create'))}>Create SPK</Button>}
                </div>
                <Card>
                    <CardContent className="space-y-4">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="Search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Code or title" />
                            <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
                                <option value="">All</option>
                                <option value="installation">Installation</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="upgrade_service">Upgrade</option>
                                <option value="relocation">Relocation</option>
                            </Select>
                            <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
                                <option value="">All</option>
                                <option value="draft">Draft</option>
                                <option value="generated">Generated</option>
                                <option value="assigned">Assigned</option>
                                <option value="in_progress">In Progress</option>
                                <option value="waiting_review">Waiting Review</option>
                                <option value="completed">Completed</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </Select>
                            <Select label="Technician" value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)}>
                                <option value="">All</option>
                                {technicians.data.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </Select>
                            <div className="self-end"><Button type="submit" variant="secondary">Filter</Button></div>
                        </form>
                        <Table>
                            <THead><TR><TH>Code</TH><TH>Title</TH><TH>Type</TH><TH>Status</TH><TH>Priority</TH><TH>Customer</TH><TH>Technician</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {workOrders.data.map((w) => (
                                    <TR key={w.id}>
                                        <TD className="font-mono text-sm">{w.code}</TD>
                                        <TD>{w.title}</TD>
                                        <TD><Badge variant="neutral">{w.type}</Badge></TD>
                                        <TD><StatusBadge variant={statusVariant(w.status)}>{w.status}</StatusBadge></TD>
                                        <TD><Badge variant={w.priority === 'urgent' ? 'danger' : w.priority === 'high' ? 'brand' : 'neutral'}>{w.priority}</Badge></TD>
                                        <TD>{w.customer?.name ?? '-'}</TD>
                                        <TD>{w.assignee?.name ?? '-'}</TD>
                                        <TD>
                                            <Link href={route('admin.spk.show', w.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination currentPage={workOrders.current_page} lastPage={workOrders.last_page} onPageChange={(page) => router.get(route('admin.spk.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
