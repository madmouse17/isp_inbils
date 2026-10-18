import { useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    Button,
    Card,
    CardContent,
    Modal,
    MonthPicker,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { useCompany } from '@/hooks/useCompany';
import { formatRupiah } from '@/lib/format';

interface PreviewRow {
    subscription_id: string;
    customer: string;
    package: string;
    active_days: number;
    days_in_period: number;
    total: number;
}

interface PreviewResponse {
    rows: PreviewRow[];
    skipped: number;
}

const previousMonth = () => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

export default function GenerateDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const company = useCompany();
    const [period, setPeriod] = useState(previousMonth());
    const [rows, setRows] = useState<PreviewRow[] | null>(null);
    const [skipped, setSkipped] = useState(0);
    const [loading, setLoading] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [creating, setCreating] = useState(false);

    const preview = async () => {
        setLoading(true);
        try {
            const { data } = await axios.post<PreviewResponse>(
                route('admin.invoices.generate-preview'),
                { period },
            );
            setRows(data.rows);
            setSkipped(data.skipped);
        } finally {
            setLoading(false);
        }
    };

    const confirm = () => {
        router.post(
            route('admin.invoices.generate'),
            { period },
            {
                onStart: () => setCreating(true),
                onSuccess: () => {
                    setConfirming(false);
                    onClose();
                },
                onFinish: () => setCreating(false),
            },
        );
    };

    return (
        <Modal open={open} onClose={onClose} title="Buat tagihan bulanan" size="xl">
            <div className="space-y-4">
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <MonthPicker
                            id="generate-period"
                            label="Periode"
                            value={period}
                            onChange={(value) => {
                                setPeriod(value);
                                setRows(null);
                            }}
                        />
                        {rows && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {rows.length} tagihan akan dibuat, {skipped} dilewati.
                                </p>
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Pelanggan</TH>
                                            <TH>Paket</TH>
                                            <TH>Hari aktif</TH>
                                            <TH className="text-right">Total</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {rows.map((r) => (
                                            <TR key={r.subscription_id}>
                                                <TD>{r.customer}</TD>
                                                <TD>{r.package}</TD>
                                                <TD>
                                                    {r.active_days}/{r.days_in_period}
                                                </TD>
                                                <TD className="text-right tabular-nums">
                                                    {formatRupiah(r.total, company)}
                                                </TD>
                                            </TR>
                                        ))}
                                        {rows.length === 0 && (
                                            <TR>
                                                <TD
                                                    colSpan={4}
                                                    className="text-center text-muted-foreground"
                                                >
                                                    Tidak ada tagihan baru untuk periode ini.
                                                </TD>
                                            </TR>
                                        )}
                                    </TBody>
                                </Table>
                            </>
                        )}
                    </CardContent>
                </Card>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant={rows !== null && rows.length > 0 ? 'outline' : 'primary'}
                        onClick={() => {
                            void preview();
                        }}
                        loading={loading}
                    >
                        Lihat pratinjau
                    </Button>
                    {rows !== null && rows.length > 0 && (
                        <Button type="button" variant="primary" onClick={() => setConfirming(true)}>
                            Buat {rows.length} tagihan
                        </Button>
                    )}
                </div>
            </div>
            <ConfirmDialog
                open={confirming}
                title={`Terbitkan ${rows?.length ?? 0} tagihan periode ${period}?`}
                description="Tagihan massal dibuat dan tercatat di buku piutang pelanggan."
                confirmLabel="Terbitkan tagihan"
                processing={creating}
                onConfirm={confirm}
                onClose={() => setConfirming(false)}
            />
        </Modal>
    );
}
