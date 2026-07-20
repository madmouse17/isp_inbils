import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';

interface TraceResult {
    id: number;
    code: string;
    name: string;
    asset_type: string;
    serial_number?: string | null;
    status: string;
    location?: { name: string; path?: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
}

interface TraceProps extends Record<string, unknown> {
    results: TraceResult[];
    search: string;
    customer_id: string;
}

export default function Trace({ results, search }: TraceProps) {
    const [query, setQuery] = useState(search);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.network-assets.trace'), { search: query }, { preserveState: true });
    };

    return (
        <AdminLayout title="Asset Trace">
            <div className="space-y-6">
                <PageHeader title="Asset Trace" subtitle="Search by serial, MAC, IP, or code." />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex gap-2">
                            <Input
                                label="Search"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Serial, MAC, IP, code"
                            />
                            <div className="self-end">
                                <Button type="submit">Trace</Button>
                            </div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Name</TH>
                                    <TH>Code</TH>
                                    <TH>Type</TH>
                                    <TH>Serial</TH>
                                    <TH>Status</TH>
                                    <TH>Location</TH>
                                    <TH>Path</TH>
                                    <TH>Customer</TH>
                                    <TH>Subscription</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {results.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={9}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    results.map((r) => (
                                        <TR key={r.id}>
                                            <TD>{r.name}</TD>
                                            <TD className="font-mono text-sm">{r.code}</TD>
                                            <TD>{r.asset_type}</TD>
                                            <TD className="font-mono text-sm">
                                                {r.serial_number ?? '-'}
                                            </TD>
                                            <TD>
                                                <StatusBadge
                                                    variant={
                                                        r.status === 'available'
                                                            ? 'success'
                                                            : r.status === 'installed'
                                                              ? 'info'
                                                              : r.status === 'maintenance'
                                                                ? 'warning'
                                                                : r.status === 'damaged'
                                                                  ? 'danger'
                                                                  : 'muted'
                                                    }
                                                >
                                                    {r.status}
                                                </StatusBadge>
                                            </TD>
                                            <TD>{r.location?.name ?? '-'}</TD>
                                            <TD>{r.location?.path ?? '-'}</TD>
                                            <TD>{r.customer?.name ?? '-'}</TD>
                                            <TD>{r.subscription?.code ?? '-'}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
