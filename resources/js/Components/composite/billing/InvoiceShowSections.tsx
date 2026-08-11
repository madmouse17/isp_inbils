import type { FormEvent } from 'react';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Modal,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { NativeSelect, StatusBadge } from '@/Components/composite';

export interface InvoiceData {
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

export function InvoiceDetailsCard({ invoice }: { invoice: InvoiceData }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <p>
                    <span className="text-muted-foreground">Customer: </span>
                    {invoice.customer?.name ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Subscription: </span>
                    {invoice.subscription?.code ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Issue Date: </span>
                    {invoice.issue_date}
                </p>
                <p>
                    <span className="text-muted-foreground">Due Date: </span>
                    {invoice.due_date}
                </p>
                <p>
                    <span className="text-muted-foreground">Type: </span>
                    {invoice.type}
                </p>
                <p>
                    <span className="text-muted-foreground">Source: </span>
                    {invoice.source}
                </p>
                {invoice.notes && (
                    <p>
                        <span className="text-muted-foreground">Notes: </span>
                        {invoice.notes}
                    </p>
                )}
                {invoice.cancel_reason && (
                    <p className="text-destructive">
                        <span className="text-muted-foreground">Cancel Reason: </span>
                        {invoice.cancel_reason}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function InvoiceStatusCard({ invoice }: { invoice: InvoiceData }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Status</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <p>
                    <span className="text-muted-foreground">Status: </span>
                    <StatusBadge variant={statusVariant(invoice.status)}>
                        {invoice.status}
                    </StatusBadge>
                </p>
                <p>
                    <span className="text-muted-foreground">Subtotal: </span>
                    {invoice.subtotal}
                </p>
                <p>
                    <span className="text-muted-foreground">Tax: </span>
                    {invoice.tax_amount}
                </p>
                <p>
                    <span className="text-muted-foreground">Discount: </span>
                    {invoice.discount_amount}
                </p>
                <p className="text-lg font-bold">
                    <span className="text-muted-foreground">Total: </span>
                    {invoice.total}
                </p>
                <p>
                    <span className="text-muted-foreground">Paid: </span>
                    {invoice.paid_amount}
                </p>
                <p className="font-medium">
                    <span className="text-muted-foreground">Remaining: </span>
                    {invoice.sisa}
                </p>
            </CardContent>
        </Card>
    );
}

export function InvoiceActionsCard({
    status,
    sisa,
    onSend,
    onAddItem,
    onRecordPayment,
    onCancel,
}: {
    status: string;
    sisa: string;
    onSend: () => void;
    onAddItem: () => void;
    onRecordPayment: () => void;
    onCancel: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Actions</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex flex-wrap gap-2">
                    {status === 'draft' && (
                        <Button type="button" onClick={onSend}>
                            Send
                        </Button>
                    )}
                    {status === 'draft' && (
                        <Button type="button" variant="secondary" onClick={onAddItem}>
                            Add Item
                        </Button>
                    )}
                    {['sent', 'partial', 'overdue'].includes(status) && sisa !== '0' && (
                        <Button type="button" onClick={onRecordPayment}>
                            Record Payment
                        </Button>
                    )}
                    {!['cancelled', 'paid'].includes(status) && (
                        <Button type="button" variant="outline" onClick={onCancel}>
                            Cancel
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export function InvoiceItemsCard({ items }: { items?: InvoiceData['items'] }) {
    return (
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
                        {(items ?? []).length === 0 ? (
                            <TR>
                                <TD colSpan={6} className="text-center text-muted-foreground">
                                    No items.
                                </TD>
                            </TR>
                        ) : (
                            (items ?? []).map((item) => (
                                <TR key={item.id}>
                                    <TD>{item.description}</TD>
                                    <TD>{item.quantity}</TD>
                                    <TD>{item.unit_price}</TD>
                                    <TD>{item.discount_amount}</TD>
                                    <TD>{item.tax_rate}%</TD>
                                    <TD className="font-medium">{item.line_total}</TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

export function InvoicePaymentsCard({ payments }: { payments?: InvoiceData['payments'] }) {
    return (
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
                        </TR>
                    </THead>
                    <TBody>
                        {(payments ?? []).length === 0 ? (
                            <TR>
                                <TD colSpan={5} className="text-center text-muted-foreground">
                                    No payments.
                                </TD>
                            </TR>
                        ) : (
                            (payments ?? []).map((payment) => (
                                <TR key={payment.id}>
                                    <TD className="font-medium">{payment.amount}</TD>
                                    <TD>
                                        <Badge variant="neutral">{payment.method}</Badge>
                                    </TD>
                                    <TD>{payment.reference ?? '-'}</TD>
                                    <TD className="text-sm">{payment.paid_at}</TD>
                                    <TD>
                                        {payment.cancelled_at ? (
                                            <Badge variant="danger">Cancelled</Badge>
                                        ) : (
                                            <Badge variant="success">Active</Badge>
                                        )}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

export function InvoicePaymentModal({
    open,
    onClose,
    invoiceSisa,
    data,
    setData,
    processing,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    invoiceSisa: string;
    data: { amount: string; method: string; reference: string; notes: string };
    setData: (field: string, value: string) => void;
    processing: boolean;
    onSubmit: (event: FormEvent) => void;
}) {
    if (!open) return null;
    return (
        <Modal open={open} onClose={onClose} title="Record Payment">
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="text-sm text-muted-foreground">Remaining: {invoiceSisa}</div>
                <Input
                    label="Amount"
                    type="number"
                    step="0.01"
                    value={data.amount}
                    onChange={(event) => setData('amount', event.target.value)}
                    required
                />
                <NativeSelect
                    label="Method"
                    value={data.method}
                    onChange={(event) => setData('method', event.target.value)}
                >
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="other">Other</option>
                </NativeSelect>
                <Input
                    label="Reference"
                    value={data.reference}
                    onChange={(event) => setData('reference', event.target.value)}
                />
                <Input
                    label="Notes"
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={processing}>
                        Record
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export function InvoiceItemModal({
    open,
    onClose,
    data,
    setData,
    processing,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    data: {
        description: string;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        tax_rate: string;
    };
    setData: (field: string, value: string) => void;
    processing: boolean;
    onSubmit: (event: FormEvent) => void;
}) {
    if (!open) return null;
    return (
        <Modal open={open} onClose={onClose} title="Add Item">
            <form onSubmit={onSubmit} className="space-y-4">
                <Input
                    label="Description"
                    value={data.description}
                    onChange={(event) => setData('description', event.target.value)}
                    required
                />
                <div className="grid gap-4 md:grid-cols-2">
                    <Input
                        label="Quantity"
                        type="number"
                        step="0.01"
                        value={data.quantity}
                        onChange={(event) => setData('quantity', event.target.value)}
                        required
                    />
                    <Input
                        label="Unit Price"
                        type="number"
                        step="0.01"
                        value={data.unit_price}
                        onChange={(event) => setData('unit_price', event.target.value)}
                        required
                    />
                    <Input
                        label="Discount"
                        type="number"
                        step="0.01"
                        value={data.discount_amount}
                        onChange={(event) => setData('discount_amount', event.target.value)}
                    />
                    <Input
                        label="Tax Rate (%)"
                        type="number"
                        step="0.01"
                        value={data.tax_rate}
                        onChange={(event) => setData('tax_rate', event.target.value)}
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={processing}>
                        Add
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export function InvoiceCancelModal({
    open,
    onClose,
    data,
    setData,
    processing,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    data: { reason: string };
    setData: (field: string, value: string) => void;
    processing: boolean;
    onSubmit: (event: FormEvent) => void;
}) {
    if (!open) return null;
    return (
        <Modal open={open} onClose={onClose} title="Cancel Invoice">
            <form onSubmit={onSubmit} className="space-y-4">
                <Input
                    label="Reason"
                    value={data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                    required
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" loading={processing}>
                        Confirm Cancel
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' {
    return status === 'paid'
        ? 'success'
        : status === 'partial'
          ? 'info'
          : status === 'overdue'
            ? 'danger'
            : status === 'cancelled'
              ? 'muted'
              : 'warning';
}
