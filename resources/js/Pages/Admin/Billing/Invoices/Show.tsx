import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Input, Modal, Select, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { StatusBadge } from '@/Components/composite';

interface InvData {
    id: number; number: string; type: string; source: string; status: string;
    issue_date: string; due_date: string; subtotal: string; tax_amount: string;
    discount_amount: string; total: string; paid_amount: string; sisa: string;
    notes?: string | null; sent_at?: string | null; cancelled_at?: string | null; cancel_reason?: string | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    items?: { id: number; description: string; quantity: string; unit_price: string; discount_amount: string; tax_rate: string; line_total: string }[];
    payments?: { id: number; amount: string; method: string; reference?: string | null; paid_at: string; cancelled_at?: string | null }[];
}

interface ShowProps extends Record<string, unknown> {
    invoice: { data: InvData };
}

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'paid' ? 'success' : s === 'partial' ? 'info' : s === 'overdue' ? 'danger' : s === 'cancelled' ? 'muted' : 'warning';

export default function Show({ invoice }: ShowProps) {
    const inv = invoice.data;
    const [paymentModal, setPaymentModal] = useState(false);
    const [itemModal, setItemModal] = useState(false);
    const [cancelModal, setCancelModal] = useState(false);
    const { data, setData, post, processing, reset } = useForm({
        amount: '', method: 'cash', reference: '', notes: '',
        description: '', quantity: '1', unit_price: '', discount_amount: '0', tax_rate: '0', reason: '',
    });

    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.payments.store', inv.id), { onSuccess: () => { setPaymentModal(false); reset(); } });
    };

    const submitItem = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.items.store', inv.id), { onSuccess: () => { setItemModal(false); reset(); } });
    };

    const submitCancel = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.cancel', inv.id), { onSuccess: () => { setCancelModal(false); reset(); } });
    };

    return (
        <AdminLayout title={`Invoice ${inv.number}`}>
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">{inv.number}</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">
                            <StatusBadge variant={statusVariant(inv.status)}>{inv.status}</StatusBadge> · {inv.type} · {inv.source}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.invoices.index'))}>Back</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader><CardTitle>Invoice Details</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p><span className="text-surface-500 dark:text-surface-400">Customer: </span>{inv.customer?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Subscription: </span>{inv.subscription?.code ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Issue Date: </span>{inv.issue_date}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Due Date: </span>{inv.due_date}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Subtotal: </span>{inv.subtotal}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Tax: </span>{inv.tax_amount}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Discount: </span>{inv.discount_amount}</p>
                        <p className="text-lg font-bold"><span className="text-surface-500 dark:text-surface-400">Total: </span>{inv.total}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Paid: </span>{inv.paid_amount}</p>
                        <p className="font-medium"><span className="text-surface-500 dark:text-surface-400">Remaining: </span>{inv.sisa}</p>
                        {inv.notes && <p className="md:col-span-2"><span className="text-surface-500 dark:text-surface-400">Notes: </span>{inv.notes}</p>}
                        {inv.cancel_reason && <p className="md:col-span-2 text-danger"><span className="text-surface-500 dark:text-surface-400">Cancel Reason: </span>{inv.cancel_reason}</p>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Actions</CardTitle></CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {inv.status === 'draft' && <Button type="button" onClick={() => router.post(route('admin.invoices.send', inv.id))}>Send</Button>}
                            {inv.status === 'draft' && <Button type="button" variant="secondary" onClick={() => setItemModal(true)}>Add Item</Button>}
                            {['sent', 'partial', 'overdue'].includes(inv.status) && inv.sisa !== '0' && <Button type="button" onClick={() => setPaymentModal(true)}>Record Payment</Button>}
                            {!['cancelled', 'paid'].includes(inv.status) && <Button type="button" variant="outline" onClick={() => setCancelModal(true)}>Cancel</Button>}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Items</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Description</TH><TH>Qty</TH><TH>Unit Price</TH><TH>Discount</TH><TH>Tax%</TH><TH>Line Total</TH></TR></THead>
                            <TBody>
                                {(inv.items ?? []).length === 0 ? <TR><TD colSpan={6} className="text-center text-surface-500">No items.</TD></TR> :
                                (inv.items ?? []).map((i) => (
                                    <TR key={i.id}>
                                        <TD>{i.description}</TD>
                                        <TD>{i.quantity}</TD>
                                        <TD>{i.unit_price}</TD>
                                        <TD>{i.discount_amount}</TD>
                                        <TD>{i.tax_rate}%</TD>
                                        <TD className="font-medium">{i.line_total}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Payments</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Amount</TH><TH>Method</TH><TH>Reference</TH><TH>Paid At</TH><TH>Status</TH></TR></THead>
                            <TBody>
                                {(inv.payments ?? []).length === 0 ? <TR><TD colSpan={5} className="text-center text-surface-500">No payments.</TD></TR> :
                                (inv.payments ?? []).map((p) => (
                                    <TR key={p.id}>
                                        <TD className="font-medium">{p.amount}</TD>
                                        <TD><Badge variant="neutral">{p.method}</Badge></TD>
                                        <TD>{p.reference ?? '-'}</TD>
                                        <TD className="text-sm">{p.paid_at}</TD>
                                        <TD>{p.cancelled_at ? <Badge variant="danger">Cancelled</Badge> : <Badge variant="success">Active</Badge>}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Modal open={paymentModal} onClose={() => setPaymentModal(false)} title="Record Payment">
                    <form onSubmit={submitPayment} className="space-y-4">
                        <div className="text-sm text-surface-500">Remaining: {inv.sisa}</div>
                        <Input label="Amount" type="number" step="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} required />
                        <Select label="Method" value={data.method} onChange={(e) => setData('method', e.target.value)}>
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </Select>
                        <Input label="Reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                        <Input label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setPaymentModal(false)}>Cancel</Button>
                            <Button type="submit" loading={processing}>Record</Button>
                        </div>
                    </form>
                </Modal>

                <Modal open={itemModal} onClose={() => setItemModal(false)} title="Add Item">
                    <form onSubmit={submitItem} className="space-y-4">
                        <Input label="Description" value={data.description} onChange={(e) => setData('description', e.target.value)} required />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Quantity" type="number" step="0.01" value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} required />
                            <Input label="Unit Price" type="number" step="0.01" value={data.unit_price} onChange={(e) => setData('unit_price', e.target.value)} required />
                            <Input label="Discount" type="number" step="0.01" value={data.discount_amount} onChange={(e) => setData('discount_amount', e.target.value)} />
                            <Input label="Tax Rate (%)" type="number" step="0.01" value={data.tax_rate} onChange={(e) => setData('tax_rate', e.target.value)} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setItemModal(false)}>Cancel</Button>
                            <Button type="submit" loading={processing}>Add</Button>
                        </div>
                    </form>
                </Modal>

                <Modal open={cancelModal} onClose={() => setCancelModal(false)} title="Cancel Invoice">
                    <form onSubmit={submitCancel} className="space-y-4">
                        <Input label="Reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} required />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setCancelModal(false)}>Cancel</Button>
                            <Button type="submit" variant="danger" loading={processing}>Confirm Cancel</Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
