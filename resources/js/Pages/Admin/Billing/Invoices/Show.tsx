import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    Input,
    Modal,
    NativeSelect,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/format';
import type { PaymentAttemptEntry } from '@/types/portal';

interface InvData {
    id: number;
    number: string;
    type: string;
    source: string;
    status: string;
    issue_date: string;
    due_date: string;
    subtotal: string;
    tax_amount: string;
    discount_amount: string;
    total: string;
    paid_amount: string;
    sisa: string;
    notes?: string | null;
    sent_at?: string | null;
    cancelled_at?: string | null;
    cancel_reason?: string | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    items?: {
        id: number;
        description: string;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        tax_rate: string;
        line_total: string;
    }[];
    payments?: {
        id: number;
        amount: string;
        method: string;
        reference?: string | null;
        paid_at: string;
        cancelled_at?: string | null;
    }[];
}

interface ShowProps extends Record<string, unknown> {
    invoice: { data: InvData };
    onlinePayments: { enabled: boolean; attempts: { data: PaymentAttemptEntry[] } };
}

const attemptVariant: Record<string, 'success' | 'warning' | 'danger' | 'muted'> = {
    paid: 'success',
    pending: 'warning',
    failed: 'muted',
    expired: 'muted',
    exception: 'danger',
};

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'paid'
        ? 'success'
        : s === 'partial'
          ? 'info'
          : s === 'overdue'
            ? 'danger'
            : s === 'cancelled'
              ? 'muted'
              : 'warning';

export default function Show({ invoice, onlinePayments }: ShowProps) {
    const inv = invoice.data;
    const attempts = onlinePayments.attempts.data;
    const { can } = usePermission();
    const [paymentModal, setPaymentModal] = useState(false);
    const [itemModal, setItemModal] = useState(false);
    const [cancelModal, setCancelModal] = useState(false);
    const [reverseTarget, setReverseTarget] = useState<number | null>(null);
    const { data, setData, post, processing, reset } = useForm({
        amount: '',
        method: 'cash',
        reference: '',
        notes: '',
        description: '',
        quantity: '1',
        unit_price: '',
        discount_amount: '0',
        tax_rate: '0',
        reason: '',
    });

    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.payments.store', inv.id), {
            onSuccess: () => {
                setPaymentModal(false);
                reset();
            },
        });
    };

    const submitItem = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.items.store', inv.id), {
            onSuccess: () => {
                setItemModal(false);
                reset();
            },
        });
    };

    const submitCancel = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.cancel', inv.id), {
            onSuccess: () => {
                setCancelModal(false);
                reset();
            },
        });
    };

    const submitReverse = (e: FormEvent) => {
        e.preventDefault();
        if (reverseTarget === null) return;
        post(route('admin.invoices.payments.reverse', [inv.id, reverseTarget]), {
            onSuccess: () => {
                setReverseTarget(null);
                reset();
            },
        });
    };

    return (
        <AdminLayout title={`Invoice ${inv.number}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Invoice ${inv.number}`}
                    subtitle="Record details and related activity."
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.invoices.index')}>Back</Link>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    window.open(route('admin.invoices.pdf', inv.id), '_blank')
                                }
                            >
                                Download PDF
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Customer: </span>
                                {inv.customer?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Subscription: </span>
                                {inv.subscription?.code ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Issue Date: </span>
                                {inv.issue_date}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Due Date: </span>
                                {inv.due_date}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Type: </span>
                                {inv.type}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Source: </span>
                                {inv.source}
                            </p>
                            {inv.notes && (
                                <p>
                                    <span className="text-muted-foreground">Notes: </span>
                                    {inv.notes}
                                </p>
                            )}
                            {inv.cancel_reason && (
                                <p className="text-destructive">
                                    <span className="text-muted-foreground">Cancel Reason: </span>
                                    {inv.cancel_reason}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <StatusBadge variant={statusVariant(inv.status)}>
                                    {inv.status}
                                </StatusBadge>
                            </p>
                            <p>
                                <span className="text-muted-foreground">Subtotal: </span>
                                {inv.subtotal}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Tax: </span>
                                {inv.tax_amount}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Discount: </span>
                                {inv.discount_amount}
                            </p>
                            <p className="text-lg font-bold">
                                <span className="text-muted-foreground">Total: </span>
                                {inv.total}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Paid: </span>
                                {inv.paid_amount}
                            </p>
                            <p className="font-medium">
                                <span className="text-muted-foreground">Remaining: </span>
                                {inv.sisa}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Actions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {inv.status === 'draft' && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        router.post(route('admin.invoices.send', inv.id))
                                    }
                                >
                                    Send
                                </Button>
                            )}
                            {inv.status === 'draft' && (
                                <Button
                                    type="button"
                                    variant="success"
                                    onClick={() => setItemModal(true)}
                                >
                                    Add Item
                                </Button>
                            )}
                            {['sent', 'partial', 'overdue'].includes(inv.status) &&
                                inv.sisa !== '0' && (
                                    <Button type="button" onClick={() => setPaymentModal(true)}>
                                        Record Payment
                                    </Button>
                                )}
                            {!['cancelled', 'paid'].includes(inv.status) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setCancelModal(true)}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Items</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Description</TH>
                                    <TH>Qty</TH>
                                    <TH>Unit Price</TH>
                                    <TH>Discount</TH>
                                    <TH>Tax%</TH>
                                    <TH>Line Total</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(inv.items ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="text-center text-muted-foreground"
                                        >
                                            No items.
                                        </TD>
                                    </TR>
                                ) : (
                                    (inv.items ?? []).map((i) => (
                                        <TR key={i.id}>
                                            <TD>{i.description}</TD>
                                            <TD>{i.quantity}</TD>
                                            <TD>{i.unit_price}</TD>
                                            <TD>{i.discount_amount}</TD>
                                            <TD>{i.tax_rate}%</TD>
                                            <TD className="font-medium">{i.line_total}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Payments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Amount</TH>
                                    <TH>Method</TH>
                                    <TH>Reference</TH>
                                    <TH>Paid At</TH>
                                    <TH>Status</TH>
                                    <TH></TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(inv.payments ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="text-center text-muted-foreground"
                                        >
                                            No payments.
                                        </TD>
                                    </TR>
                                ) : (
                                    (inv.payments ?? []).map((p) => (
                                        <TR key={p.id}>
                                            <TD className="font-medium">{p.amount}</TD>
                                            <TD>
                                                <Badge variant="neutral">{p.method}</Badge>
                                            </TD>
                                            <TD>{p.reference ?? '-'}</TD>
                                            <TD className="text-sm">{p.paid_at}</TD>
                                            <TD>
                                                {p.cancelled_at ? (
                                                    <Badge variant="danger">Cancelled</Badge>
                                                ) : (
                                                    <Badge variant="success">Active</Badge>
                                                )}
                                            </TD>
                                            <TD>
                                                {!p.cancelled_at &&
                                                    can('billing.payment.reverse') && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => setReverseTarget(p.id)}
                                                        >
                                                            Reverse
                                                        </Button>
                                                    )}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                {(onlinePayments.enabled || attempts.length > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Online payments</CardTitle>
                            <CardDescription>
                                Midtrans attempts for this invoice. Read-only: a payment is booked
                                only when Midtrans confirms it.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Status</TH>
                                        <TH>Amount</TH>
                                        <TH>Method</TH>
                                        <TH>Transaction ID</TH>
                                        <TH>Created</TH>
                                        <TH>Settled</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {attempts.length === 0 ? (
                                        <TR>
                                            <TD
                                                colSpan={6}
                                                className="text-center text-muted-foreground"
                                            >
                                                No online attempts yet.
                                            </TD>
                                        </TR>
                                    ) : (
                                        attempts.map((a) => (
                                            <TR key={a.id}>
                                                <TD>
                                                    <StatusBadge variant={attemptVariant[a.status]}>
                                                        {a.status}
                                                    </StatusBadge>
                                                    {a.status_reason && (
                                                        <p
                                                            className={`mt-1 max-w-xs text-xs ${a.status === 'exception' ? 'text-destructive' : 'text-muted-foreground'}`}
                                                        >
                                                            Reason: {a.status_reason}
                                                        </p>
                                                    )}
                                                </TD>
                                                <TD className="font-medium">{a.amount}</TD>
                                                <TD>{a.payment_type ?? '-'}</TD>
                                                <TD className="font-mono text-xs break-all">
                                                    {a.transaction_id ?? '-'}
                                                </TD>
                                                <TD className="text-sm">
                                                    {formatDateTime(a.created_at)}
                                                </TD>
                                                <TD className="text-sm">
                                                    {formatDateTime(a.settled_at)}
                                                </TD>
                                            </TR>
                                        ))
                                    )}
                                </TBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <Modal
                    open={paymentModal}
                    onClose={() => setPaymentModal(false)}
                    title="Record Payment"
                >
                    <form onSubmit={submitPayment} className="space-y-4">
                        <div className="text-sm text-muted-foreground">Remaining: {inv.sisa}</div>
                        <Input
                            label="Amount"
                            type="number"
                            step="0.01"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            required
                        />
                        <NativeSelect
                            label="Method"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                        >
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </NativeSelect>
                        <Input
                            label="Reference"
                            value={data.reference}
                            onChange={(e) => setData('reference', e.target.value)}
                        />
                        <Input
                            label="Notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setPaymentModal(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="success" loading={processing}>
                                Record
                            </Button>
                        </div>
                    </form>
                </Modal>

                <Modal open={itemModal} onClose={() => setItemModal(false)} title="Add Item">
                    <form onSubmit={submitItem} className="space-y-4">
                        <Input
                            label="Description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            required
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Quantity"
                                type="number"
                                step="0.01"
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                required
                            />
                            <Input
                                label="Unit Price"
                                type="number"
                                step="0.01"
                                value={data.unit_price}
                                onChange={(e) => setData('unit_price', e.target.value)}
                                required
                            />
                            <Input
                                label="Discount"
                                type="number"
                                step="0.01"
                                value={data.discount_amount}
                                onChange={(e) => setData('discount_amount', e.target.value)}
                            />
                            <Input
                                label="Tax Rate (%)"
                                type="number"
                                step="0.01"
                                value={data.tax_rate}
                                onChange={(e) => setData('tax_rate', e.target.value)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setItemModal(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="success" loading={processing}>
                                Add
                            </Button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    open={cancelModal}
                    onClose={() => setCancelModal(false)}
                    title="Cancel Invoice"
                >
                    <form onSubmit={submitCancel} className="space-y-4">
                        <Input
                            label="Reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            required
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setCancelModal(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="danger" loading={processing}>
                                Confirm Cancel
                            </Button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    open={reverseTarget !== null}
                    onClose={() => setReverseTarget(null)}
                    title="Reverse Payment"
                >
                    <form onSubmit={submitReverse} className="space-y-4">
                        <div className="text-sm text-muted-foreground">
                            Reversing a payment decrements the invoice paid amount and re-derives
                            its status. The payment is marked cancelled with the actor recorded.
                        </div>
                        <Input
                            label="Reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            required
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setReverseTarget(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="danger" loading={processing}>
                                Confirm Reverse
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
