import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, Input } from '@/Components/ui';

interface StockResult {
    location_name: string; location_path: string; quantity: string; reserved: string; available: string;
}

interface FindResult {
    id: number; sku: string; name: string; stocks: StockResult[];
}

interface FindProps extends Record<string, unknown> {
    results: FindResult[];
    search: string;
}

export default function Find({ results, search }: FindProps) {
    const [query, setQuery] = useState(search);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.inventory.find'), { search: query }, { preserveState: true });
    };

    return (
        <AdminLayout title="Item Finder">
            <div className="space-y-6">
                <div>
                    <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Item Finder</h2>
                    <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Search consumable items by name or SKU.</p>
                </div>
                <Card>
                    <CardContent>
                        <form onSubmit={submit} className="flex gap-2">
                            <Input label="Search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Name or SKU" />
                            <div className="self-end"><Button type="submit">Search</Button></div>
                        </form>
                    </CardContent>
                </Card>
                {results.length > 0 && (
                    <div className="space-y-4">
                        {results.map((r) => (
                            <Card key={r.id}>
                                <CardContent>
                                    <div className="mb-3">
                                        <p className="text-lg font-semibold text-surface-900 dark:text-surface-100">{r.name}</p>
                                        <p className="text-sm text-surface-500">{r.sku}</p>
                                    </div>
                                    <div className="space-y-2">
                                        {r.stocks.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between rounded-lg border border-surface-200 p-3 dark:border-surface-800">
                                                <div>
                                                    <p className="font-medium text-surface-900 dark:text-surface-100">{s.location_name}</p>
                                                    <p className="text-sm text-surface-500">{s.location_path}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-medium">{s.available} available</p>
                                                    <p className="text-sm text-surface-500">{s.quantity} total · {s.reserved} reserved</p>
                                                </div>
                                            </div>
                                        ))}
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
