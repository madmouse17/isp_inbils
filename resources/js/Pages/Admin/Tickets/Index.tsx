import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Select, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { StatusBadge } from '@/Components/composite';

interface TRow {
    id: number; code: string; title: string; source: string; status: string; priority: string;
    is_sla_breached: boolean; sla_deadline?: string | null;
    category?: { name: string } | null;
    customer?: { name: string } | null;
    assignee?: { name: string } | null;
}

interface CatRow { id: number; name: string }
interface HandlerRow { id: number; name: string }

interface IndexProps extends Record<string, unknown> {
    tickets: { data: TRow[]; current_page: number; last_page: number };
    categories: { data: CatRow[] };
    handlers: { data: HandlerRow[] };
    filters: { status?: string; source?: string; category_id?: string; assigned_to?: string; sla_breached?: string; search?: string };
    can: { create: boolean };
}

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'closed' ? 'muted' : s === 'resolved' ? 'success' : s === 'on_progress' ? 'info' : s === 'assigned' ? 'warning' : 'danger';

export default function Index({ tickets, categories, handlers, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [source, setSource] = useState(filters.source ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.tickets.index'), { search, status, source, category_id: categoryId }, { preserveState: true });
    };

    return (
        <AdminLayout title="Tickets">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Tickets</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Customer service tickets.</p>
                    </div>
                    {can.create && <Button type="button" onClick={() => router.get(route('admin.tickets.create'))}>Create Ticket</Button>}
                </div>
                <Card>
                    <CardContent className="space-y-4">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="Search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Code or title" />
                            <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
                                <option value="">All</option>
                                <option value="open">Open</option>
                                <option value="assigned">Assigned</option>
                                <option value="on_progress">On Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </Select>
                            <Select label="Source" value={source} onChange={(e) => setSource(e.target.value)}>
                                <option value="">All</option>
                                <option value="customer">Customer</option>
                                <option value="noc">NOC</option>
                                <option value="internal">Internal</option>
                            </Select>
                            <Select label="Category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
                                <option value="">All</option>
                                {categories.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <div className="self-end"><Button type="submit" variant="secondary">Filter</Button></div>
                        </form>
                        <Table>
                            <THead><TR><TH>Code</TH><TH>Title</TH><TH>Category</TH><TH>Source</TH><TH>Status</TH><TH>Priority</TH><TH>SLA</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {tickets.data.map((t) => (
                                    <TR key={t.id}>
                                        <TD className="font-mono text-sm">{t.code}</TD>
                                        <TD>{t.title}</TD>
                                        <TD>{t.category?.name ?? '-'}</TD>
                                        <TD><Badge variant="neutral">{t.source}</Badge></TD>
                                        <TD><StatusBadge variant={statusVariant(t.status)}>{t.status}</StatusBadge></TD>
                                        <TD><Badge variant={t.priority === 'urgent' ? 'danger' : t.priority === 'high' ? 'brand' : 'neutral'}>{t.priority}</Badge></TD>
                                        <TD>{t.is_sla_breached ? <Badge variant="danger">Breached</Badge> : <Badge variant="success">OK</Badge>}</TD>
                                        <TD><Link href={route('admin.tickets.show', t.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link></TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination currentPage={tickets.current_page} lastPage={tickets.last_page} onPageChange={(page) => router.get(route('admin.tickets.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
