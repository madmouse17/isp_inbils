import { useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Button, Input, Modal, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface PreviewRow {
    subscription_id: number;
    customer: string;
    package: string;
    active_days: number;
    days_in_period: number;
    total: number;
}

const previousMonth = () => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

const idr = (n: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

export default function GenerateDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [period, setPeriod] = useState(previousMonth());
    const [rows, setRows] = useState<PreviewRow[] | null>(null);
    const [skipped, setSkipped] = useState(0);
    const [loading, setLoading] = useState(false);

    const preview = async () => {
        setLoading(true);
        try {
            const { data } = await axios.post(route('admin.invoices.generate-preview'), { period });
            setRows(data.rows);
            setSkipped(data.skipped);
        } finally {
            setLoading(false);
        }
    };

    const confirm = () => {
        router.post(route('admin.invoices.generate'), { period }, { onSuccess: onClose });
    };

    return (
        <Modal open={open} onClose={onClose} title="Generate Tagihan Bulanan" size="xl">
            <div className="space-y-4">
                <Input label="Periode" type="month" value={period} onChange={(e) => { setPeriod(e.target.value); setRows(null); }} />
                {rows && (
                    <>
                        <p className="text-sm text-surface-500 dark:text-surface-400">
                            {rows.length} tagihan akan dibuat, {skipped} dilewati.
                        </p>
                        <Table>
                            <THead><TR><TH>Pelanggan</TH><TH>Paket</TH><TH>Hari</TH><TH className="text-right">Total</TH></TR></THead>
                            <TBody>
                                {rows.map((r) => (
                                    <TR key={r.subscription_id}>
                                        <TD>{r.customer}</TD>
                                        <TD>{r.package}</TD>
                                        <TD>{r.active_days}/{r.days_in_period}</TD>
                                        <TD className="text-right">{idr(r.total)}</TD>
                                    </TR>
                                ))}
                                {rows.length === 0 && <TR><TD colSpan={4} className="text-center text-surface-500">Tidak ada tagihan baru.</TD></TR>}
                            </TBody>
                        </Table>
                    </>
                )}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Batal</Button>
                    <Button type="button" onClick={preview} loading={loading}>Preview</Button>
                    {rows !== null && rows.length > 0 && <Button type="button" onClick={confirm}>Terbitkan {rows.length} Tagihan</Button>}
                </div>
            </div>
        </Modal>
    );
}
