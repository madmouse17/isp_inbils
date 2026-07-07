import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, Textarea, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface SubRef { id: number; code: string; status: string }
interface Row {
    customer_id: number;
    customer: string;
    current: number;
    b1_30: number;
    b31_60: number;
    b61_90: number;
    b90_plus: number;
    total: number;
    invoice_count: number;
    subscriptions: SubRef[];
}

const idr = (n: number) => (n > 0 ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n) : '-');

export default function Receivables({ rows, can }: { rows: Row[]; can: { suspend: boolean } }) {
    const [suspending, setSuspending] = useState<SubRef | null>(null);
    const [reason, setReason] = useState('');

    const suspend = () => {
        if (!suspending) return;
        router.post(route('admin.subscriptions.suspend', suspending.id), { reason }, {
            onSuccess: () => { setSuspending(null); setReason(''); },
        });
    };

    return (
        <AdminLayout title="Tunggakan">
            <div className="space-y-6">
                <PageHeader title="Tunggakan" subtitle="Pantau umur piutang pelanggan dan tindakan isolir." />
                <Card>
                    <CardContent className="space-y-4 overflow-x-auto pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Pelanggan</TH>
                                    <TH className="text-right">Belum Jatuh Tempo</TH>
                                    <TH className="text-right">1–30 hari</TH>
                                    <TH className="text-right">31–60 hari</TH>
                                    <TH className="text-right">61–90 hari</TH>
                                    <TH className="text-right">&gt;90 hari</TH>
                                    <TH className="text-right">Total</TH>
                                    <TH />
                                </TR>
                            </THead>
                            <TBody>
                                {rows.length === 0 ? (
                                    <TR>
                                        <TD colSpan={8} className="py-10 text-center text-muted-foreground">No data found.</TD>
                                    </TR>
                                ) : (
                                    rows.map((r) => (
                                        <TR key={r.customer_id}>
                                            <TD>
                                                <Link className="text-brand-600 hover:underline" href={route('admin.invoices.index', { customer_id: r.customer_id })}>
                                                    {r.customer}
                                                </Link>
                                                <span className="ml-1 text-surface-400">({r.invoice_count} invoice)</span>
                                            </TD>
                                            <TD className="text-right">{idr(r.current)}</TD>
                                            <TD className="text-right">{idr(r.b1_30)}</TD>
                                            <TD className="text-right">{idr(r.b31_60)}</TD>
                                            <TD className="text-right">{idr(r.b61_90)}</TD>
                                            <TD className="text-right font-semibold text-red-600">{idr(r.b90_plus)}</TD>
                                            <TD className="text-right font-semibold">{idr(r.total)}</TD>
                                            <TD className="text-right">
                                                {can.suspend && r.subscriptions.map((s) => (
                                                    <Button key={s.id} type="button" variant="secondary" size="sm" onClick={() => setSuspending(s)}>
                                                        Isolir {s.code}
                                                    </Button>
                                                ))}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                {suspending && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <Card className="w-full max-w-md">
                            <CardContent className="space-y-4 pt-6">
                                <p>Isolir subscription <strong>{suspending.code}</strong>?</p>
                                <Textarea
                                    placeholder="Alasan isolir (wajib)"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="secondary" onClick={() => setSuspending(null)}>Batal</Button>
                                    <Button type="button" onClick={suspend} disabled={!reason.trim()}>Isolir</Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
