import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Trash2, Upload } from 'lucide-react';
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
    FileUpload,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
} from '@/Components/ui';
import {
    CustomerAcquirerValue,
    CustomerRelatedTables,
    DataTable,
    NativeSelect,
    PageHeader,
    type CustomerHistory,
    type CustomerHistoryAccess,
    type DataTableColumn,
} from '@/Components/composite';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
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
import type { MediaItem } from '@/types/document';

interface DocumentTypeOption {
    code: string;
    name: string;
    expiry_days: number | null;
}

interface ShowProps extends Record<string, unknown> {
    customer: { data: Customer & { whatsapp: CustomerWhatsApp } };
    history: CustomerHistory;
    historyAccess: CustomerHistoryAccess;
    /** The latest 20 WhatsApp rows of this customer, newest first. */
    whatsappMessages: WhatsAppMessageRow[];
    documents: MediaItem[];
    documentTypes: DocumentTypeOption[];
    documentModelType: string;
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

function CustomerDocumentsPanel({
    customerId,
    modelType,
    documents,
    documentTypes,
    canUpdate,
}: {
    customerId: string;
    modelType: string;
    documents: MediaItem[];
    documentTypes: DocumentTypeOption[];
    canUpdate: boolean;
}) {
    const form = useForm({
        model_type: modelType,
        model_id: customerId,
        file: null as File | null,
        document_type_code: '',
        collection: 'documents',
    });
    const [deleting, setDeleting] = useState<MediaItem | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('admin.documents.media.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('file', 'document_type_code'),
        });
    };

    const remove = () => {
        if (!deleting) return;

        router.delete(route('admin.documents.media.destroy', deleting.id), {
            preserveScroll: true,
            onStart: () => setDeleteProcessing(true),
            onSuccess: () => setDeleting(null),
            onFinish: () => setDeleteProcessing(false),
        });
    };

    return (
        <div className="space-y-6">
            {canUpdate ? (
                <form onSubmit={submit} className="rounded-lg border border-border bg-muted/20 p-4">
                    <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] md:items-end">
                        <NativeSelect
                            label="Jenis dokumen"
                            value={form.data.document_type_code}
                            onChange={(event) =>
                                form.setData('document_type_code', event.target.value)
                            }
                            error={form.errors.document_type_code}
                            required
                        >
                            <option value="">Pilih jenis</option>
                            {documentTypes.map((type) => (
                                <option key={type.code} value={type.code}>
                                    {type.name}
                                    {type.expiry_days ? ` (${type.expiry_days} hari)` : ''}
                                </option>
                            ))}
                        </NativeSelect>
                        <FileUpload
                            label="Berkas"
                            value={form.data.file}
                            onChange={(file) => form.setData('file', file)}
                            acceptedFileTypes={['application/pdf', 'image/jpeg', 'image/png']}
                            maxFileSize="10MB"
                            error={form.errors.file}
                            hint="PDF, JPG, atau PNG. Maksimum 10 MB."
                            required
                        />
                        <Button
                            type="submit"
                            variant="primary"
                            loading={form.processing}
                            disabled={!form.data.file || !form.data.document_type_code}
                            leftIcon={<Upload className="size-4" />}
                        >
                            Unggah
                        </Button>
                    </div>
                </form>
            ) : null}

            {documents.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
                    Belum ada dokumen. Unggah KTP atau kontrak pelanggan.
                </div>
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>Dokumen</TH>
                            <TH>Berlaku</TH>
                            <TH>Diunggah</TH>
                            <TH className="text-right">Aksi</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {documents.map((document) => (
                            <TR key={document.id}>
                                <TD>
                                    <p className="font-medium text-foreground">
                                        {document.document_type_name ||
                                            document.document_type ||
                                            'Dokumen'}
                                    </p>
                                    <p className="mt-1 text-xs break-all text-muted-foreground">
                                        {document.file_name}
                                    </p>
                                </TD>
                                <TD>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span>
                                            {document.expires_at
                                                ? `Berlaku sampai ${formatDate(document.expires_at)}`
                                                : 'Tanpa masa berlaku'}
                                        </span>
                                        {document.expiry === 'expiring' ? (
                                            <Badge variant="warning">Segera habis</Badge>
                                        ) : null}
                                        {document.expiry === 'expired' ? (
                                            <Badge variant="danger">Kedaluwarsa</Badge>
                                        ) : null}
                                    </div>
                                </TD>
                                <TD>{formatDateTime(document.created_at)}</TD>
                                <TD>
                                    <div className="flex justify-end gap-2">
                                        {document.url ? (
                                            <Button
                                                asChild
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                leftIcon={<ExternalLink className="size-4" />}
                                            >
                                                <a
                                                    href={document.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    Lihat
                                                </a>
                                            </Button>
                                        ) : null}
                                        {canUpdate ? (
                                            <Button
                                                type="button"
                                                variant="danger"
                                                size="sm"
                                                leftIcon={<Trash2 className="size-4" />}
                                                onClick={() => setDeleting(document)}
                                            >
                                                Hapus
                                                <span className="sr-only">
                                                    {' '}
                                                    {document.file_name}
                                                </span>
                                            </Button>
                                        ) : null}
                                    </div>
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            )}

            <ConfirmDialog
                open={deleting !== null}
                title={`Hapus ${deleting?.file_name ?? 'dokumen'}?`}
                description="Berkas ini akan dihapus permanen dari data pelanggan."
                confirmLabel="Hapus dokumen"
                tone="danger"
                processing={deleteProcessing}
                onConfirm={remove}
                onClose={() => setDeleting(null)}
            />
        </div>
    );
}

export default function Show({
    customer,
    history,
    historyAccess,
    whatsappMessages,
    documents,
    documentTypes,
    documentModelType,
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
                    extraTab={{
                        value: 'documents',
                        label: `Dokumen (${documents.length})`,
                        content: (
                            <CustomerDocumentsPanel
                                customerId={String(c.id)}
                                modelType={documentModelType}
                                documents={documents}
                                documentTypes={documentTypes}
                                canUpdate={can.update}
                            />
                        ),
                    }}
                />
            </div>
        </AdminLayout>
    );
}
