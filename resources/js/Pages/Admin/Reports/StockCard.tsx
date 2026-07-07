import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface StockData extends Record<string, unknown> {
    product_id?: number;
    movements?: { id: number; movement_type: string; quantity: string; balance_after: string; from_location?: string | null; to_location?: string | null; note?: string | null; created_at: string }[];
}

interface Props extends Record<string, unknown> {
    data?: StockData;
    filters: { product_id?: string; date_from?: string; date_to?: string };
}

export default function StockCard({ data, filters }: Props) {
    const [productId, setProductId] = useState(filters.product_id ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.reports.stock-card'), { product_id: productId, date_from: dateFrom, date_to: dateTo }, { preserveState: true });
    };

    return (
        <AdminLayout title="Stock Card">
            <div className="space-y-6">
                <PageHeader title="Stock Card" subtitle="Movement history per product/location." />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input label="Product ID" type="number" value={productId} onChange={(e) => setProductId(e.target.value)} required />
                            <Input label="From" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            <Input label="To" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            <div className="self-end"><Button type="submit">Run</Button></div>
                        </form>
                    </CardContent>
                </Card>
                {data?.movements && (
                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <Table>
                                <THead><TR><TH>Type</TH><TH>Qty</TH><TH>Balance</TH><TH>From</TH><TH>To</TH><TH>Note</TH><TH>Date</TH></TR></THead>
                                <TBody>
                                    {data.movements.length === 0 ? (
                                        <TR><TD colSpan={7} className="py-10 text-center text-muted-foreground">No data found.</TD></TR>
                                    ) : data.movements.map((m) => (
                                        <TR key={m.id}>
                                            <TD>{m.movement_type}</TD><TD>{m.quantity}</TD><TD>{m.balance_after}</TD>
                                            <TD>{m.from_location ?? '-'}</TD><TD>{m.to_location ?? '-'}</TD>
                                            <TD>{m.note ?? '-'}</TD><TD className="text-sm">{m.created_at}</TD>
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
