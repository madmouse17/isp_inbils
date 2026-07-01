import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, Input } from '@/Components/ui';
import { StatusBadge } from '@/Components/composite';

interface TraceResult {
    id: number; code: string; name: string; asset_type: string; serial_number?: string | null;
    status: string; location?: { name: string; path?: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
}

interface TraceProps extends Record<string, unknown> {
    results: TraceResult[];
    search: string;
    customer_id: string;
}

export default function Trace({ results, search, customer_id }: TraceProps) {
    const [query, setQuery] = useState(search);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.network-assets.trace'), { search: query }, { preserveState: true });
    };

    return (
        <AdminLayout title="Asset Trace">
            <div className="space-y-6">
                <div>
                    <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Asset Trace</h2>
                    <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Search by serial, MAC, IP, or code.</p>
                </div>
                <Card>
                    <CardContent>
                        <form onSubmit={submit} className="flex gap-2">
                            <Input label="Search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Serial, MAC, IP, code" />
                            <div className="self-end"><Button type="submit">Trace</Button></div>
                        </form>
                    </CardContent>
                </Card>
                {results.length > 0 && (
                    <div className="space-y-4">
                        {results.map((r) => (
                            <Card key={r.id}>
                                <CardContent>
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="text-lg font-semibold text-surface-900 dark:text-surface-100">{r.name}</p>
                                            <p className="text-sm text-surface-500">{r.code} · {r.asset_type}</p>
                                            <p className="text-sm text-surface-500">Serial: {r.serial_number ?? '-'}</p>
                                        </div>
                                        <StatusBadge variant={r.status === 'available' ? 'success' : r.status === 'installed' ? 'info' : r.status === 'maintenance' ? 'warning' : r.status === 'damaged' ? 'danger' : 'muted'}>{r.status}</StatusBadge>
                                    </div>
                                    <div className="mt-4 grid gap-2 text-sm md:grid-cols-2">
                                        <p><span className="text-surface-500 dark:text-surface-400">Location: </span>{r.location?.name ?? '-'}</p>
                                        <p><span className="text-surface-500 dark:text-surface-400">Path: </span>{r.location?.path ?? '-'}</p>
                                        <p><span className="text-surface-500 dark:text-surface-400">Customer: </span>{r.customer?.name ?? '-'}</p>
                                        <p><span className="text-surface-500 dark:text-surface-400">Subscription: </span>{r.subscription?.code ?? '-'}</p>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
