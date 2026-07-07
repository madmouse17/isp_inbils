import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, StatusBadge } from '@/Components/composite';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import type { Product, Stock, StockMovement } from '@/types/inventory';

interface ShowProps extends Record<string, unknown> {
    product: { data: Product };
}

export default function Show({ product }: ShowProps) {
    const p = product.data;
    const stocks: Stock[] = p.stocks ?? [];
    const movements: StockMovement[] = p.movements ?? [];

    return (
        <AdminLayout title={p.name}>
            <div className="space-y-6">
                <PageHeader
                    title={p.name}
                    subtitle={p.sku}
                    actions={(
                        <>
                            <Badge variant={p.is_active ? 'success' : 'danger'}>{p.is_active ? 'Active' : 'Inactive'}</Badge>
                            <Button type="button" variant="secondary" onClick={() => history.back()}>Back</Button>
                            <Button type="button" variant="secondary" onClick={() => window.location.href = route('admin.products.edit', p.id)}>Edit</Button>
                        </>
                    )}
                />

                <Card>
                    <CardHeader><CardTitle>Product Details</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p><span className="text-surface-500 dark:text-surface-400">Category: </span>{p.category?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Unit: </span>{p.unit?.name ?? '-'} ({p.unit?.symbol ?? '-'})</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Sell Price: </span>{p.sell_price ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Cost Price: </span>{p.cost_price ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Min Stock: </span>{p.min_stock ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Track Stock: </span>{p.track_stock ? <Badge variant="brand">Yes</Badge> : '-'}</p>
                        {p.description && <p className="md:col-span-2"><span className="text-surface-500 dark:text-surface-400">Description: </span>{p.description}</p>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Stocks per Location</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Location</TH><TH>Path</TH><TH>Quantity</TH><TH>Reserved</TH><TH>Available</TH></TR></THead>
                            <TBody>
                                {stocks.length === 0 ? <TR><TD colSpan={5} className="py-10 text-center text-muted-foreground">No stock records.</TD></TR> :
                                stocks.map((s) => (
                                    <TR key={s.id}>
                                        <TD>{s.location?.name ?? '-'}</TD>
                                        <TD className="text-sm text-muted-foreground">{s.location?.path ?? '-'}</TD>
                                        <TD>{s.quantity}</TD>
                                        <TD>{s.reserved_quantity}</TD>
                                        <TD>{s.available}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Recent Movements</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Type</TH><TH>Quantity</TH><TH>Balance After</TH><TH>Note</TH><TH>Date</TH></TR></THead>
                            <TBody>
                                {movements.length === 0 ? <TR><TD colSpan={5} className="py-10 text-center text-muted-foreground">No movements.</TD></TR> :
                                movements.map((m) => (
                                    <TR key={m.id}>
                                        <TD><StatusBadge variant={m.movement_type === 'receive' ? 'success' : m.movement_type === 'issue' ? 'danger' : 'info'}>{m.movement_type}</StatusBadge></TD>
                                        <TD>{m.quantity}</TD>
                                        <TD>{m.balance_after ?? '-'}</TD>
                                        <TD>{m.note ?? '-'}</TD>
                                        <TD className="text-sm text-muted-foreground">{m.created_at ?? '-'}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <div className="mt-3 flex justify-end">
                            <Link href={route('admin.stock-movements.index', { product_id: p.id })} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">View all movements →</Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
