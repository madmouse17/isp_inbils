import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface PkgRow {
    id: number; code: string; name: string; price_mrc: string; price_otc: string;
    contract_min_months: number | null; is_active: boolean;
    bandwidth_profile: { name: string } | null;
    speed_profile: { name: string } | null;
    sla_tier: { name: string } | null;
}

interface OptRow { id: number; name: string }

interface IndexProps extends Record<string, unknown> {
    servicePackages: { data: PkgRow[]; current_page: number; last_page: number; per_page: number; total: number };
    slaTiers: { data: OptRow[] };
    filters: { is_active?: string; sla_tier_id?: string; search?: string };
    can: { create: boolean };
}

export default function Index({ servicePackages, slaTiers, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [isActive, setIsActive] = useState(filters.is_active ?? '');
    const [slaTierId, setSlaTierId] = useState(filters.sla_tier_id ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.service-packages.index'), { search, is_active: isActive, sla_tier_id: slaTierId }, { preserveState: true });
    };

    const remove = (p: PkgRow) => {
        if (window.confirm(`Delete ${p.name}?`)) router.delete(route('admin.service-packages.destroy', p.id));
    };

    return (
        <AdminLayout title="Service Packages">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Service Packages</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Manage internet service packages.</p>
                    </div>
                    {can.create && <Button type="button" onClick={() => router.get(route('admin.service-packages.create'))}>Create</Button>}
                </div>
                <Card>
                    <CardContent className="space-y-4">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="Search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Code or name" />
                            <Input label="Active" value={isActive} onChange={(e) => setIsActive(e.target.value)} placeholder="true/false" />
                            <Input label="SLA Tier ID" value={slaTierId} onChange={(e) => setSlaTierId(e.target.value)} placeholder="Filter by SLA" />
                            <div className="self-end"><Button type="submit" variant="secondary">Filter</Button></div>
                        </form>
                        <Table>
                            <THead><TR><TH>Code</TH><TH>Name</TH><TH>Bandwidth</TH><TH>Speed</TH><TH>SLA</TH><TH>MRC</TH><TH>OTC</TH><TH>Status</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {servicePackages.data.map((p) => (
                                    <TR key={p.id}>
                                        <TD className="font-mono text-sm">{p.code}</TD>
                                        <TD>{p.name}</TD>
                                        <TD>{p.bandwidth_profile?.name ?? '-'}</TD>
                                        <TD>{p.speed_profile?.name ?? '-'}</TD>
                                        <TD>{p.sla_tier?.name ?? '-'}</TD>
                                        <TD>{p.price_mrc}</TD>
                                        <TD>{p.price_otc}</TD>
                                        <TD><Badge variant={p.is_active ? 'success' : 'danger'}>{p.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                        <TD>
                                            <div className="flex gap-2">
                                                <Link href={route('admin.service-packages.show', p.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link>
                                                <Link href={route('admin.service-packages.edit', p.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</Link>
                                                <Button type="button" variant="ghost" size="sm" onClick={() => remove(p)}>Delete</Button>
                                            </div>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination currentPage={servicePackages.current_page} lastPage={servicePackages.last_page} onPageChange={(page) => router.get(route('admin.service-packages.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
