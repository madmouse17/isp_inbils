import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Select, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface EvalRow {
    id: number; reference_type: string; reference_id: number; score: string;
    customer_rating?: string | null; evaluated_at: string; comment?: string | null;
    employee?: { name: string } | null;
    evaluator?: { name: string } | null;
}

interface EmpRow { id: number; name: string }

interface IndexProps extends Record<string, unknown> {
    evaluations: { data: EvalRow[]; current_page: number; last_page: number };
    employees: { data: EmpRow[] };
    filters: { employee_id?: string; reference_type?: string; search?: string };
    can: { create: boolean };
}

export default function Index({ evaluations, employees, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [employeeId, setEmployeeId] = useState(filters.employee_id ?? '');
    const [refType, setRefType] = useState(filters.reference_type ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.evaluations.index'), { search, employee_id: employeeId, reference_type: refType }, { preserveState: true });
    };

    return (
        <AdminLayout title="Evaluations">
            <div className="space-y-6">
                <PageHeader
                    title="Employee Evaluations"
                    subtitle="Performance evaluations for SPK and tickets."
                    actions={can.create && <Button type="button" onClick={() => router.get(route('admin.evaluations.create'))}>Create</Button>}
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="Search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Employee name" />
                            <Select label="Employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                                <option value="">All</option>
                                {employees.data.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                            </Select>
                            <Select label="Reference Type" value={refType} onChange={(e) => setRefType(e.target.value)}>
                                <option value="">All</option>
                                <option value="WorkOrder">SPK</option>
                                <option value="Ticket">Ticket</option>
                            </Select>
                            <div className="self-end"><Button type="submit" variant="secondary">Filter</Button></div>
                        </form>
                        <Table>
                            <THead><TR><TH>Employee</TH><TH>Reference</TH><TH>Score</TH><TH>Customer Rating</TH><TH>Evaluator</TH><TH>Date</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {evaluations.data.length === 0 ? (
                                    <TR><TD colSpan={7} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                                ) : evaluations.data.map((ev) => (
                                    <TR key={ev.id}>
                                        <TD>{ev.employee?.name ?? '-'}</TD>
                                        <TD><Badge variant="neutral">{ev.reference_type}:{ev.reference_id}</Badge></TD>
                                        <TD className="font-medium">{ev.score}</TD>
                                        <TD>{ev.customer_rating ?? '-'}</TD>
                                        <TD>{ev.evaluator?.name ?? '-'}</TD>
                                        <TD className="text-sm">{ev.evaluated_at}</TD>
                                        <TD><Link href={route('admin.evaluations.show', ev.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link></TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination currentPage={evaluations.current_page} lastPage={evaluations.last_page} onPageChange={(page) => router.get(route('admin.evaluations.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
