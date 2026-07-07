import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface CustomerRow {
    id: number;
    code: string;
    name: string;
    type: string;
    email?: string | null;
    phone?: string | null;
    is_active: boolean;
    addresses_count?: number;
    subscriptions_count?: number;
}

interface IndexProps extends Record<string, unknown> {
    customers: { data: CustomerRow[]; current_page: number; last_page: number; per_page: number; total: number };
    filters: { type?: string; is_active?: string; search?: string };
}

export default function Index({ customers, filters }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [isActive, setIsActive] = useState(filters.is_active ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.customers.index'), { search, type, is_active: isActive }, { preserveState: true });
    };

    const remove = (c: CustomerRow) => {
        if (window.confirm(`Delete ${c.name}?`)) router.delete(route('admin.customers.destroy', c.id));
    };

    return (
        <AdminLayout title="Customers">
            <div className="space-y-6">
                <PageHeader
                    title="Customers"
                    subtitle="Manage customer master data."
                    actions={<Button type="button" onClick={() => router.get(route('admin.customers.create'))}>Create Customer</Button>}
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="Search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, code, phone" />
                            <Input label="Type" value={type} onChange={(e) => setType(e.target.value)} placeholder="Individual / Company" />
                            <Input label="Active" value={isActive} onChange={(e) => setIsActive(e.target.value)} placeholder="true / false" />
                            <div className="self-end"><Button type="submit" variant="secondary">Filter</Button></div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Code</TH>
                                    <TH>Name</TH>
                                    <TH>Type</TH>
                                    <TH>Phone</TH>
                                    <TH>Status</TH>
                                    <TH>Subs</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {customers.data.length === 0 ? (
                                    <TR>
                                        <TD colSpan={7} className="py-10 text-center text-muted-foreground">No data found.</TD>
                                    </TR>
                                ) : (
                                    customers.data.map((c) => (
                                        <TR key={c.id}>
                                            <TD className="font-mono text-sm">{c.code}</TD>
                                            <TD>{c.name}</TD>
                                            <TD><Badge variant={c.type === 'Company' ? 'brand' : 'neutral'}>{c.type}</Badge></TD>
                                            <TD>{c.phone ?? '-'}</TD>
                                            <TD><Badge variant={c.is_active ? 'success' : 'danger'}>{c.is_active ? 'Active' : 'Inactive'}</Badge></TD>
                                            <TD>{c.subscriptions_count ?? 0}</TD>
                                            <TD>
                                                <div className="flex flex-wrap gap-2">
                                                    <Link href={route('admin.customers.show', c.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link>
                                                    <Link href={route('admin.customers.edit', c.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</Link>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => remove(c)}>Delete</Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination currentPage={customers.current_page} lastPage={customers.last_page} onPageChange={(page) => router.get(route('admin.customers.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
