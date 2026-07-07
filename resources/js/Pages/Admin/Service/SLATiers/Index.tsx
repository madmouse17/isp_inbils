import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface SlaRow {
    id: number; name: string; uptime_pct: string; response_time_hours: number;
    resolution_time_hours: number; credit_pct: string; is_active: boolean;
}

interface IndexProps extends Record<string, unknown> {
    slaTiers: { data: SlaRow[]; current_page: number; last_page: number };
    can: { create: boolean };
}

export default function Index({ slaTiers, can }: IndexProps) {
    const remove = (s: SlaRow) => {
        if (window.confirm(`Delete ${s.name}?`)) router.delete(route('admin.sla-tiers.destroy', s.id));
    };

    return (
        <AdminLayout title="SLA Tiers">
            <div className="space-y-6">
                <PageHeader
                    title="SLA Tiers"
                    subtitle="Manage Service Level Agreement tiers."
                    actions={can.create && <Button type="button" onClick={() => router.get(route('admin.sla-tiers.create'))}>Create</Button>}
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead><TR><TH>Name</TH><TH>Uptime</TH><TH>Response</TH><TH>Resolution</TH><TH>Credit</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {slaTiers.data.length === 0 ? (
                                    <TR>
                                        <TD colSpan={7} className="py-10 text-center text-muted-foreground">No data found.</TD>
                                    </TR>
                                ) : (
                                    slaTiers.data.map((s) => (
                                        <TR key={s.id}>
                                            <TD>{s.name}</TD>
                                            <TD>{s.uptime_pct}%</TD>
                                            <TD>{s.response_time_hours}h</TD>
                                            <TD>{s.resolution_time_hours}h</TD>
                                            <TD>{s.credit_pct}%</TD>
                                            <TD><Badge variant={s.is_active ? 'success' : 'danger'}>{s.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Link href={route('admin.sla-tiers.edit', s.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</Link>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => remove(s)}>Delete</Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination currentPage={slaTiers.current_page} lastPage={slaTiers.last_page} onPageChange={(page) => router.get(route('admin.sla-tiers.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
