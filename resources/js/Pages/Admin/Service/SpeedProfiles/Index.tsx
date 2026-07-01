import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface SpRow {
    id: number; name: string; download_max_mbps: number; upload_max_mbps: number;
    burst_download_mbps: number | null; burst_upload_mbps: number | null;
    radius_profile_name: string | null; is_active: boolean;
}

interface IndexProps extends Record<string, unknown> {
    speedProfiles: { data: SpRow[]; current_page: number; last_page: number };
    can: { create: boolean };
}

export default function Index({ speedProfiles, can }: IndexProps) {
    const remove = (s: SpRow) => {
        if (window.confirm(`Delete ${s.name}?`)) router.delete(route('admin.speed-profiles.destroy', s.id));
    };

    return (
        <AdminLayout title="Speed Profiles">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Speed Profiles</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Manage RADIUS speed profiles.</p>
                    </div>
                    {can.create && <Button type="button" onClick={() => router.get(route('admin.speed-profiles.create'))}>Create</Button>}
                </div>
                <Card>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Name</TH><TH>Down Max</TH><TH>Up Max</TH><TH>Burst Down</TH><TH>Burst Up</TH><TH>RADIUS</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {speedProfiles.data.map((s) => (
                                    <TR key={s.id}>
                                        <TD>{s.name}</TD>
                                        <TD>{s.download_max_mbps} Mbps</TD>
                                        <TD>{s.upload_max_mbps} Mbps</TD>
                                        <TD>{s.burst_download_mbps ? `${s.burst_download_mbps} Mbps` : '-'}</TD>
                                        <TD>{s.burst_upload_mbps ? `${s.burst_upload_mbps} Mbps` : '-'}</TD>
                                        <TD className="font-mono text-sm">{s.radius_profile_name ?? '-'}</TD>
                                        <TD><Badge variant={s.is_active ? 'success' : 'danger'}>{s.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                        <TD>
                                            <div className="flex gap-2">
                                                <Link href={route('admin.speed-profiles.edit', s.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</Link>
                                                <Button type="button" variant="ghost" size="sm" onClick={() => remove(s)}>Delete</Button>
                                            </div>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination currentPage={speedProfiles.current_page} lastPage={speedProfiles.last_page} onPageChange={(page) => router.get(route('admin.speed-profiles.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
