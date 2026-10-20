import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input, Modal, Textarea } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatRupiah } from '@/lib/format';

/** The online payment attempt finance is closing as returned to the customer. */
export interface RefundTarget {
    /** Public id of the attempt. */
    id: string;
    invoiceNumber: string | null;
    /** Decimal string, as the attempt stores it. */
    amount: string;
}

interface RefundAttemptDialogProps {
    /** The attempt to mark; null keeps the dialog closed. The caller clears it from `onClose`. */
    target: RefundTarget | null;
    onClose: () => void;
}

/**
 * Marks an online payment that Inbils could not book as returned to the customer (K4). Finance fills in
 * the reference of the refund and a note after the money went back through Midtrans or a bank transfer;
 * the server audits the mark. A refusal of the attempt itself stays in the dialog, next to the choice.
 */
export function RefundAttemptDialog({ target, onClose }: RefundAttemptDialogProps) {
    const company = useCompany();
    const form = useForm({ reference: '', note: '' });
    // `refund` is the server's refusal of the attempt (not an exception, or already booked), not a field.
    const refusal = (form.errors as Record<string, string | undefined>).refund;

    const close = () => {
        if (form.processing) return;
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!target) return;

        form.post(route('admin.payment-attempts.refund', target.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={target !== null} onClose={close} title="Tandai pembayaran dikembalikan">
            <form onSubmit={submit} className="space-y-4">
                {target ? (
                    <p className="text-sm">
                        Pembayaran online {formatRupiah(target.amount, company)}
                        {target.invoiceNumber ? ` untuk tagihan ${target.invoiceNumber}` : ''}.
                    </p>
                ) : null}
                <p className="text-sm text-muted-foreground">
                    Isi setelah uang dikembalikan lewat dasbor Midtrans atau transfer bank.
                    Tindakan ini dicatat di log audit.
                </p>
                {refusal ? (
                    <Alert variant="danger">
                        <AlertDescription>{refusal}</AlertDescription>
                    </Alert>
                ) : null}
                <Input
                    id="refund-reference"
                    label="Nomor referensi pengembalian"
                    value={form.data.reference}
                    onChange={(event) => form.setData('reference', event.target.value)}
                    error={form.errors.reference}
                    maxLength={100}
                    autoComplete="off"
                    required
                />
                <Textarea
                    id="refund-note"
                    label="Catatan"
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    error={form.errors.note}
                    maxLength={500}
                    rows={3}
                    required
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={close}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Simpan
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
