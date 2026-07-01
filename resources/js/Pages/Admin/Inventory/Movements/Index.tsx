import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Select, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface MovementRow {
    id: number; movement_type: string; quantity: string; balance_after: string;
    note?: string | null; created_at: string;
    product?: { sku: string; name: string } | null;
    from_location?: { name: string } | null;
    to_location?: { name: string } | null;
}

interface IndexProps extends Record<string, unknown> {
    movements: { data: MovementRow[]; current_page: number; last_page: number };
    filters: { product_id?: string; movement_type?: string; location_id?: string };
}

export default function Index({ movements, filters }: IndexProps) {
    const [movementType, setMovementType] = useState(filters.movement_type ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.stock-movements.index'), { movement_type: movementType }, { preserveState: true });
    };

    return (
        <AdminLayout title="Stock Movements">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Stock Movements</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Immutable audit trail.</p>
                    </div>
                    <Button type="button" variant="secondary" onClick={() => router.get(route('admin.stocks.index'))}>Stocks</Button>
                </div>
                <Card>
                    <CardContent className="space-y-4">
                        <form onSubmit={submit} className="flex gap-2">
                            <Select label="Type" value={movementType} onChange={(e) => setMovementType(e.target.value)}>
                                <option value="">All</option>
                                <option value="receive">Receive</option>
                                <option value="issue">Issue</option>
                                <option value="transfer">Transfer</option>
                                <option value="adjustment">Adjustment</option>
                                <option value="reserve">Reserve</option>
                                <option value="release">Release</option>
                                <option value="return">Return</option>
                            </Select>
                            <div className="self-end"><Button type="submit" variant="secondary">Filter</Button></div>
                        </form>
                        <Table>
                            <THead><TR><TH>Type</TH><TH>Product</TH><TH>From</TH><TH>To</TH><TH>Quantity</TH><TH>Balance</TH><TH>Note</TH><TH>Date</TH></TR></THead>
                            <TBody>
                                {movements.data.map((m) => (
                                    <TR key={m.id}>
                                        <TD><Badge variant="neutral">{m.movement_type}</Badge></TD>
                                        <TD>{m.product?.name ?? '-'}</TD>
                                        <TD>{m.from_location?.name ?? '-'}</TD>
                                        <TD>{m.to_location?.name ?? '-'}</TD>
                                        <TD>{m.quantity}</TD>
                                        <TD>{m.balance_after}</TD>
                                        <TD>{m.note ?? '-'}</TD>
                                        <TD className="text-sm text-surface-500">{m.created_at}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination currentPage={movements.current_page} lastPage={movements.last_page} onPageChange={(page) => router.get(route('admin.stock-movements.index'), { page })} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
