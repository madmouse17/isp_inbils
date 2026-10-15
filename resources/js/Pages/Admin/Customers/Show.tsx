import { useState } from 'react';
import type { ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
} from '@/Components/ui';
import {
    CustomerAcquirerValue,
    CustomerRelatedTables,
    DataTable,
    PageHeader,
    type CustomerHistory,
    type CustomerHistoryAccess,
    type DataTableColumn,
} from '@/Components/composite';
import { formatDate, formatDateTime } from '@/lib/format';
import {
    ACTIVE_STATE,
    CUSTOMER_STATUS,
    CUSTOMER_TYPE,
    WHATSAPP_SKIP_REASON,
    WHATSAPP_STATUS,
    labelOf,
} from '@/lib/statuses';
import type { Customer } from '@/types/models';
import type { CustomerWhatsApp, WhatsAppMessageRow } from '@/types/whatsapp';

interface ShowProps extends Record<string, unknown> {
    customer: { data: Customer & { whatsapp: CustomerWhatsApp } };
    history: CustomerHistory;
    historyAccess: CustomerHistoryAccess;
    /** The latest 20 WhatsApp rows of this customer, newest first. */
    whatsappMessages: WhatsAppMessageRow[];
    can: { viewAcquirer: boolean; update: boolean };
}

const eventLabels: Record<string, string> = {
    invoice_issued: 'Tagihan terbit',
    invoice_reminder: 'Pengingat jatuh tempo',
    payment_received: 'Pembayaran diterima',
    ticket_created: 'Tiket dibuat',
    ticket_assigned: 'Tiket mulai ditangani',
    ticket_resolved: 'Tiket selesai',
    spk_scheduled: 'Penugasan SPK ke teknisi',
    spk_scheduled_customer: 'Jadwal kunjungan teknisi',
    access_link_notice: 'Info link akses portal',
};

const subjectLabels: Record<string, string> = {
    invoice: 'Tagihan',
    ticket: 'Tiket',
    work_order: 'SPK',
};

const messageColumns: DataTableColumn<WhatsAppMessageRow>[] = [
    {
        key: 'event',
        header: 'Pesan',
        cell: (row) => eventLabels[row.event] ?? row.event,
    },
    {
        key: 'subject',
        header: 'Subjek',
        cell: (row) =>
            row.subject_type === 'user'
                ? 'Akun portal'
                : row.subject_type && row.subject_id !== null
                  ? `${subjectLabels[row.subject_type] ?? row.subject_type} #${row.subject_id}`
                  : '-',
    },
    {
        key: 'status',
        header: 'Status',
        cell: (row) => <EnumBadge map={WHATSAPP_STATUS} value={row.status} />,
    },
    {
        key: 'reason',
        header: 'Keterangan',
        cell: (row) =>
            row.skip_reason ? (
                labelOf(WHATSAPP_SKIP_REASON, row.skip_reason)
            ) : row.error_code ? (
                <span className="font-mono">Kode galat {row.error_code}</span>
            ) : (
                <EmptyCell />
            ),
    },
    {
        key: 'recipient',
        header: 'Nomor',
        cell: (row) =>
            row.recipient_last4 ? (
                <span className="font-mono">••••{row.recipient_last4}</span>
            ) : (
                <EmptyCell />
            ),
    },
    {
        key: 'created_at',
        header: 'Waktu',
        cell: (row) => <span className="whitespace-nowrap">{formatDateTime(row.created_at)}</span>,
    },
];

function ConsentBadge({ whatsapp }: { whatsapp: CustomerWhatsApp }) {
    if (whatsapp.opted_in) {
        return <Badge variant="success">Setuju sejak {formatDate(whatsapp.opted_in_at)}</Badge>;
    }

    if (whatsapp.opted_out_at) {
        return <Badge variant="warning">Berhenti sejak {formatDate(whatsapp.opted_out_at)}</Badge>;
    }

    return <Badge variant="neutral">Belum setuju</Badge>;
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </>
    );
}

export default function Show({
    customer,
    history,
    historyAccess,
    whatsappMessages,
    can,
}: ShowProps) {
    const c = customer.data;
    const [sendingLink, setSendingLink] = useState(false);

    const resendAccessLink = () =>
        router.post(
            route('admin.customers.access-link', c.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setSendingLink(true),
                onFinish: () => setSendingLink(false),
            },
        );

    return (
        <AdminLayout title={`Pelanggan ${c.code ?? ''}`}>
            <div className="space-y-6">
                <PageHeader
                    title={c.name ?? ''}
                    subtitle={`${c.code ?? ''} - ${labelOf(CUSTOMER_TYPE, c.type)}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.customers.index')}>Kembali</Link>
                            </Button>
                            {can.update ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    loading={sendingLink}
                                    onClick={resendAccessLink}
                                >
                                    Kirim ulang link akses
                                </Button>
                            ) : null}
                            <Button asChild variant="primary" size="sm">
                                <Link href={route('admin.customers.edit', c.id)}>Ubah</Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data pelanggan</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Email">{c.email || <EmptyCell />}</DetailRow>
                                <DetailRow label="Telepon">{c.phone || <EmptyCell />}</DetailRow>
                                <DetailRow label="NPWP">{c.tax_id || <EmptyCell />}</DetailRow>
                                <DetailRow label="Narahubung">
                                    {c.contact_person || <EmptyCell />}
                                </DetailRow>
                                {can.viewAcquirer ? (
                                    <DetailRow label="Diperoleh oleh">
                                        <CustomerAcquirerValue acquirer={c.acquirer} />
                                    </DetailRow>
                                ) : null}
                                {c.notes ? <DetailRow label="Catatan">{c.notes}</DetailRow> : null}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] items-center gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Tipe">
                                    <EnumBadge map={CUSTOMER_TYPE} value={c.type} />
                                </DetailRow>
                                <DetailRow label="Status">
                                    <EnumBadge map={CUSTOMER_STATUS} value={c.customer_status} />
                                </DetailRow>
                                <DetailRow label="Akun">
                                    <EnumBadge map={ACTIVE_STATE} value={Boolean(c.is_active)} />
                                </DetailRow>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-3">
                        <CardTitle>WhatsApp</CardTitle>
                        <ConsentBadge whatsapp={c.whatsapp} />
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!c.whatsapp.reachable && (
                            <Alert variant="warning">
                                <AlertDescription>
                                    Nomor telepon pelanggan bukan nomor seluler Indonesia yang
                                    valid. Pesan WhatsApp tidak akan terkirim sampai nomor
                                    diperbaiki.
                                </AlertDescription>
                            </Alert>
                        )}
                        <DataTable
                            columns={messageColumns}
                            rows={whatsappMessages}
                            rowKey="id"
                            emptyTitle="Belum ada pesan WhatsApp"
                            emptyDescription="Pesan akan muncul di sini setelah pelanggan setuju dan ada tagihan, pembayaran, tiket, atau jadwal kunjungan."
                        />
                    </CardContent>
                </Card>

                <CustomerRelatedTables
                    addresses={c.addresses ?? []}
                    contacts={c.contacts ?? []}
                    subscriptions={c.subscriptions ?? []}
                    history={history}
                    historyAccess={historyAccess}
                />
            </div>
        </AdminLayout>
    );
}
