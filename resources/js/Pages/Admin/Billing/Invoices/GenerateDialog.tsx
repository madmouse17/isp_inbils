import { useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    Alert,
    Button,
    Card,
    CardContent,
    Checkbox,
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
    total: string;
}

/** A subscription the run leaves out, with the reason in words (the server writes it). */
interface SkippedRow {
    subscription_code: string;
    customer: string;
    reason: string;
    reason_label: string;
}

interface PreviewResponse {
    rows: PreviewRow[];
    /** All of them; `skipped_rows` names the first 50. */
    skipped: number;
    skipped_rows: SkippedRow[];
    /** The month still in progress on the company clock: its invoices reach customers at once. */
    is_current_period: boolean;
}

/** Skipped subscriptions shown before "Lihat yang dilewati". */
const SKIPPED_SHOWN = 5;

const previousMonth = () => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

export default function GenerateDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const company = useCompany();
    const [period, setPeriod] = useState(previousMonth());
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [periodError, setPeriodError] = useState<string | undefined>();
    const [allSkipped, setAllSkipped] = useState(false);
    const [understood, setUnderstood] = useState(false);
    const [loading, setLoading] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [creating, setCreating] = useState(false);

    const rows = preview?.rows ?? null;
    const skippedRows = preview?.skipped_rows ?? [];
    const creatable = rows !== null && rows.length > 0;
    const currentPeriod = creatable && preview?.is_current_period === true;

    const runPreview = async () => {
        setLoading(true);
        setPeriodError(undefined);
        try {
            const { data } = await axios.post<PreviewResponse>(
                route('admin.invoices.generate-preview'),
                { period },
            );
            setPreview(data);
            setAllSkipped(false);
            setUnderstood(false);
        } catch (error) {
            const response = axios.isAxiosError<{ errors?: { period?: string[] } }>(error)
                ? error.response
                : undefined;
            setPreview(null);
            setPeriodError(
                response?.data?.errors?.period?.[0] ?? 'Pratinjau gagal dimuat. Coba lagi.',
            );
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
                onError: (errors) => {
                    setConfirming(false);
                    setPeriodError(errors.period);
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
                            error={periodError}
                            onChange={(value) => {
                                setPeriod(value);
                                setPreview(null);
                                setPeriodError(undefined);
                            }}
                        />
                        {preview && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {preview.rows.length} tagihan akan dibuat, {preview.skipped}{' '}
                                    dilewati.
                                </p>
                                {currentPeriod && (
                                    <Alert variant="warning">
                                        Periode berjalan belum selesai. Tagihan langsung terkirim ke
                                        pelanggan.
                                    </Alert>
                                )}
                                {skippedRows.length > 0 && (
                                    <section
                                        aria-labelledby="generate-skipped"
                                        className="space-y-2"
                                    >
                                        <h3 id="generate-skipped" className="text-sm font-medium">
                                            Dilewati
                                        </h3>
                                        <Table>
                                            <THead>
                                                <TR>
                                                    <TH>Langganan</TH>
                                                    <TH>Pelanggan</TH>
                                                    <TH>Alasan</TH>
                                                </TR>
                                            </THead>
                                            <TBody>
                                                {(allSkipped
                                                    ? skippedRows
                                                    : skippedRows.slice(0, SKIPPED_SHOWN)
                                                ).map((row) => (
                                                    <TR key={row.subscription_code}>
                                                        <TD className="font-mono text-xs">
                                                            {row.subscription_code}
                                                        </TD>
                                                        <TD>{row.customer}</TD>
                                                        <TD>{row.reason_label}</TD>
                                                    </TR>
                                                ))}
                                            </TBody>
                                        </Table>
                                        {skippedRows.length > SKIPPED_SHOWN && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                aria-expanded={allSkipped}
                                                onClick={() => setAllSkipped((all) => !all)}
                                            >
                                                {allSkipped
                                                    ? 'Ringkas daftar'
                                                    : 'Lihat yang dilewati'}
                                            </Button>
                                        )}
                                        {preview.skipped > skippedRows.length && (
                                            <p className="text-sm text-muted-foreground">
                                                Dan {preview.skipped - skippedRows.length} lainnya.
                                            </p>
                                        )}
                                    </section>
                                )}
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
                                        {preview.rows.map((r) => (
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
                                        {preview.rows.length === 0 && (
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
                {currentPeriod && (
                    <Checkbox
                        id="generate-understood"
                        label="Saya mengerti, buat tagihan periode berjalan"
                        checked={understood}
                        onCheckedChange={(checked) => setUnderstood(checked === true)}
                    />
                )}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant={creatable ? 'outline' : 'primary'}
                        onClick={() => {
                            void runPreview();
                        }}
                        loading={loading}
                    >
                        Lihat pratinjau
                    </Button>
                    {creatable && (
                        <Button
                            type="button"
                            variant="primary"
                            disabled={currentPeriod && !understood}
                            onClick={() => setConfirming(true)}
                        >
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
