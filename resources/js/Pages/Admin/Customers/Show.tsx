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
} from '@/Components/ui';
import {
    CustomerAcquirerValue,
    CustomerRelatedTables,
    DataTable,
    PageHeader,
    StatusBadge,
    type CustomerHistory,
    type CustomerHistoryAccess,
    type DataTableColumn,
} from '@/Components/composite';
import { ToneBadge, type BadgeTone } from '@/Components/composite/ToneBadge';
import { CustomerStatusBadge } from '@/lib/customerStatus';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Customer } from '@/types/models';
import type { CustomerWhatsApp, WhatsAppMessageRow, WhatsAppStatus } from '@/types/whatsapp';

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
    invoice: 'Invoice',
    ticket: 'Tiket',
    work_order: 'SPK',
};

const skipReasonLabels: Record<string, string> = {
    disabled: 'WhatsApp perusahaan nonaktif',
    event_disabled: 'Jenis pesan dinonaktifkan',
    no_consent: 'Pelanggan belum setuju',
    opted_out: 'Pelanggan berhenti (STOP)',
    invalid_phone: 'Nomor tidak valid',
    budget_exceeded: 'Batas pesan bulanan tercapai',
};

const statusBadges: Record<WhatsAppStatus, { tone: BadgeTone; label: string }> = {
    queued: { tone: 'secondary', label: 'Antrean' },
    sent: { tone: 'info', label: 'Terkirim' },
    delivered: { tone: 'success', label: 'Diterima' },
    read: { tone: 'success', label: 'Dibaca' },
    failed: { tone: 'danger', label: 'Gagal' },
    skipped: { tone: 'warning', label: 'Dilewati' },
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
                  : '—',
    },
    {
        key: 'status',
        header: 'Status',
        cell: (row) => (
            <ToneBadge tone={statusBadges[row.status].tone}>
                {statusBadges[row.status].label}
            </ToneBadge>
        ),
    },
    {
        key: 'reason',
        header: 'Keterangan',
        cell: (row) =>
            row.skip_reason ? (
                (skipReasonLabels[row.skip_reason] ?? row.skip_reason)
            ) : row.error_code ? (
                <span className="font-mono">Kode galat {row.error_code}</span>
            ) : (
                '—'
            ),
    },
    {
        key: 'recipient',
        header: 'Nomor',
        cell: (row) =>
            row.recipient_last4 ? (
                <span className="font-mono">••••{row.recipient_last4}</span>
            ) : (
                '—'
            ),
    },
    {
        key: 'created_at',
        header: 'Waktu',
        cell: (row) => formatDateTime(row.created_at),
    },
];

function ConsentBadge({ whatsapp }: { whatsapp: CustomerWhatsApp }) {
    if (whatsapp.opted_in) {
        return (
            <ToneBadge tone="success">Setuju sejak {formatDate(whatsapp.opted_in_at)}</ToneBadge>
        );
    }

    if (whatsapp.opted_out_at) {
        return (
            <ToneBadge tone="warning">Berhenti sejak {formatDate(whatsapp.opted_out_at)}</ToneBadge>
        );
    }

    return <ToneBadge tone="secondary">Belum setuju</ToneBadge>;
}

export default function Show({
    customer,
    history,
    historyAccess,
    whatsappMessages,
    can,
}: ShowProps) {
    const c = customer.data;

    return (
        <AdminLayout title={c.name ?? ''}>
            <div className="space-y-6">
                <PageHeader
                    title={c.name ?? ''}
                    subtitle={`${c.code ?? ''} - ${c.type ?? ''}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.customers.index')}>Back</Link>
                            </Button>
                            {can.update ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            route('admin.customers.access-link', c.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Kirim ulang link akses
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                variant="warning"
                                onClick={() => router.get(route('admin.customers.edit', c.id))}
                            >
                                Edit
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
                                <span className="text-muted-foreground">Email: </span>
                                {c.email ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Phone: </span>
                                {c.phone ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Tax ID: </span>
                                {c.tax_id ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Contact Person: </span>
                                {c.contact_person ?? '-'}
                            </p>
                            {can.viewAcquirer ? (
                                <div>
                                    <span className="text-muted-foreground">Acquired by: </span>
                                    <CustomerAcquirerValue acquirer={c.acquirer} />
                                </div>
                            ) : null}
                            {c.notes && (
                                <p>
                                    <span className="text-muted-foreground">Notes: </span>
                                    {c.notes}
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
                                <span className="text-muted-foreground">Type: </span>
                                <Badge variant={c.type === 'Company' ? 'brand' : 'neutral'}>
                                    {c.type}
                                </Badge>
                            </p>
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <CustomerStatusBadge status={c.customer_status} />
                            </p>
                            <p>
                                <span className="text-muted-foreground">Account: </span>
                                <StatusBadge variant={c.is_active ? 'success' : 'danger'}>
                                    {c.is_active ? 'Active' : 'Inactive'}
                                </StatusBadge>
                            </p>
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
