import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Select,
    SearchSelect,
    Pagination,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';

interface WoRow {
    id: number;
    code: string;
    type: string;
    title: string;
    status: string;
    priority: string;
    customer?: { name: string } | null;
    assignee?: { name: string } | null;
}

interface TechRow {
    id: number;
    user_id: number;
    employee_number: string;
    phone?: string | null;
    name: string;
    user?: { name: string; email: string } | null;
    organization?: { name: string; code: string } | null;
}

interface IndexProps extends Record<string, unknown> {
    workOrders: { data: WoRow[]; current_page: number; last_page: number };
    technicians: { data: TechRow[] };
    filters: { type?: string; status?: string; assigned_to?: string; search?: string };
    can: { create: boolean };
}

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'completed'
        ? 'success'
        : s === 'in_progress' || s === 'waiting_review'
          ? 'info'
          : s === 'rejected'
            ? 'danger'
            : s === 'cancelled'
              ? 'muted'
              : 'warning';

export default function Index({ workOrders, technicians, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [assignedTo, setAssignedTo] = useState(filters.assigned_to ?? '');
    const technicianOptions = technicians.data.map((technician) => ({
        value: String(technician.user_id),
        label: technician.user?.name ?? technician.name,
        description: [
            technician.employee_number,
            technician.organization?.name,
            technician.phone,
        ]
            .filter(Boolean)
            .join(' - '),
    }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.spk.index'),
            { search, type, status, assigned_to: assignedTo },
            { preserveState: true },
        );
    };

    return (
        <AdminLayout title="SPK">
            <div className="space-y-6">
                <PageHeader
                    title="Surat Perintah Kerja"
                    subtitle="Work orders for field technicians."
                    actions={
                        can.create && (
                            <Button
                                type="button"
                                onClick={() => router.get(route('admin.spk.create'))}
                            >
                                Create SPK
                            </Button>
                        )
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input
                                label="Search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Code or title"
                            />
                            <Select
                                label="Type"
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="installation">Installation</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="upgrade_service">Upgrade</option>
                                <option value="relocation">Relocation</option>
                            </Select>
                            <Select
                                label="Status"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                            >
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
                            <SearchSelect
                                label="Technician"
                                value={assignedTo}
                                onChange={setAssignedTo}
                                options={technicianOptions}
                                placeholder="Search technician"
                                emptyText="No technician employees found."
                            />
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Code</TH>
                                    <TH>Title</TH>
                                    <TH>Type</TH>
                                    <TH>Status</TH>
                                    <TH>Priority</TH>
                                    <TH>Customer</TH>
                                    <TH>Technician</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {workOrders.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={8}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    workOrders.data.map((w) => (
                                        <TR key={w.id}>
                                            <TD className="font-mono text-sm">{w.code}</TD>
                                            <TD>{w.title}</TD>
                                            <TD>
                                                <Badge variant="neutral">{w.type}</Badge>
                                            </TD>
                                            <TD>
                                                <StatusBadge variant={statusVariant(w.status)}>
                                                    {w.status}
                                                </StatusBadge>
                                            </TD>
                                            <TD>
                                                <Badge
                                                    variant={
                                                        w.priority === 'urgent'
                                                            ? 'danger'
                                                            : w.priority === 'high'
                                                              ? 'brand'
                                                              : 'neutral'
                                                    }
                                                >
                                                    {w.priority}
                                                </Badge>
                                            </TD>
                                            <TD>{w.customer?.name ?? '-'}</TD>
                                            <TD>{w.assignee?.name ?? '-'}</TD>
                                            <TD>
                                                <Link
                                                    href={route('admin.spk.show', w.id)}
                                                    className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                >
                                                    Show
                                                </Link>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={workOrders.current_page}
                            lastPage={workOrders.last_page}
                            onPageChange={(page) => router.get(route('admin.spk.index'), { page })}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
