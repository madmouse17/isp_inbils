import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface LogEntry { id: number; log_name: string; description: string; causer_id: number; subject_type?: string | null; subject_id?: number | null; created_at: string }
interface Props extends Record<string, unknown> {
    data?: LogEntry[];
    filters: { user_id?: string; log_name?: string; date_from?: string; date_to?: string };
}

export default function AuditLog({ data, filters }: Props) {
    const [userId, setUserId] = useState(filters.user_id ?? '');
    const [logName, setLogName] = useState(filters.log_name ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.reports.audit-log'), { user_id: userId, log_name: logName, date_from: dateFrom, date_to: dateTo }, { preserveState: true });
    };

    return (
        <AdminLayout title="Audit Log">
            <div className="space-y-6">
                <PageHeader title="Audit Log" subtitle="Activity trail by user/module/date." />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="User ID" type="number" value={userId} onChange={(e) => setUserId(e.target.value)} />
                            <Input label="Module" value={logName} onChange={(e) => setLogName(e.target.value)} placeholder="work_order, ticket, invoice..." />
                            <Input label="From" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            <Input label="To" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            <div className="self-end"><Button type="submit">Run</Button></div>
                        </form>
                    </CardContent>
                </Card>
                {data && (
                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <Table>
                                <THead><TR><TH>Module</TH><TH>Event</TH><TH>Subject</TH><TH>Date</TH></TR></THead>
                                <TBody>
                                    {data.length === 0 ? (
                                        <TR><TD colSpan={4} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                                    ) : data.map((l) => (
                                        <TR key={l.id}>
                                            <TD>{l.log_name}</TD><TD>{l.description}</TD>
                                            <TD className="text-sm">{l.subject_type}:{l.subject_id}</TD>
                                            <TD className="text-sm">{l.created_at}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
