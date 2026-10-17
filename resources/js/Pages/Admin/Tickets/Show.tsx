import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EnumBadge,
    FileUpload,
    Modal,
    NativeSelect,
    Progress,
    SearchSelect,
    Textarea,
    useToast,
} from '@/Components/ui';
import { MediaPreview, PageHeader } from '@/Components/composite';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime } from '@/lib/format';
import { PRIORITY, TICKET_SOURCE, TICKET_STATUS } from '@/lib/statuses';
import { checkInText, type CheckInRecord } from './components/checkIn';

type DeadlineSource = { type: 'tier' | 'category'; name: string };

interface TData {
    id: string;
    code: string;
    title: string;
    description?: string | null;
    source: string;
    status: string;
    priority: string;
    assigned_to?: string | null;
    updated_at?: string | null;
    can?: { start: boolean; close: boolean };
    is_sla_breached: boolean;
    sla_deadline?: string | null;
    response_deadline: string | null;
    deadline_source: DeadlineSource;
    is_response_breached: boolean;
    first_response_at?: string | null;
    resolved_at?: string | null;
    closed_at?: string | null;
    resolution_note?: string | null;
    check_in?: CheckInRecord | null;
    category?: { name: string; code: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    network_asset?: { code: string; name: string } | null;
    location?: { name: string; path?: string } | null;
    assignee?: { name: string } | null;
    comments?: {
        id: string;
        body: string;
        is_internal: boolean;
        created_at: string;
        author?: { name: string } | null;
    }[];
    attachments?: {
        id: string;
        url: string;
        file_path: string;
        original_name?: string | null;
        mime_type?: string | null;
        created_at: string;
    }[];
}

interface ShowProps extends Record<string, unknown> {
    ticket: { data: TData };
    /** Who the ticket can go to; empty unless the actor may assign or reassign. */
    handlers: { id: string; name: string; email: string }[];
}

type ActionModal = 'assign' | 'resolve' | 'close' | 'spawnSpk' | 'comment';

const ACTION_TITLE: Record<ActionModal, string> = {
    assign: 'Tugaskan tiket',
    resolve: 'Selesaikan tiket',
    close: 'Tutup tiket',
    spawnSpk: 'Buat SPK dari tiket',
    comment: 'Tambah komentar',
};

const ACTION_CONFIRM: Record<ActionModal, string> = {
    assign: 'Tugaskan',
    resolve: 'Selesaikan',
    close: 'Tutup tiket',
    spawnSpk: 'Buat SPK',
    comment: 'Kirim komentar',
};

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium text-foreground">{children}</dd>
        </div>
    );
}

export default function Show({ ticket, handlers }: ShowProps) {
    const t = ticket.data;
    const company = useCompany();
    const sourceLabel =
        t.deadline_source.type === 'tier'
            ? `Target ${t.deadline_source.name}`
            : `Target kategori ${t.deadline_source.name}`;
    const { toast } = useToast();
    const [actionModal, setActionModal] = useState<ActionModal | null>(null);
    const [attachmentFile, setAttachmentFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadPercentage, setUploadPercentage] = useState(0);
    const { data, setData, post, processing, reset, errors, clearErrors, transform } = useForm({
        handler_id: '',
        resolution_note: '',
        body: '',
        is_internal: false,
    });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;
        const routeMap: Record<ActionModal, string> = {
            assign: 'admin.tickets.assign',
            resolve: 'admin.tickets.resolve',
            close: 'admin.tickets.close',
            spawnSpk: 'admin.tickets.spawn-spk',
            comment: 'admin.tickets.comments.store',
        };
        // Optimistic-concurrency guard required by AssignTicketRequest.
        transform((payload) =>
            actionModal === 'assign'
                ? {
                      ...payload,
                      expected_assigned_to: t.assigned_to ?? null,
                      expected_updated_at: t.updated_at ?? null,
                  }
                : payload,
        );
        post(route(routeMap[actionModal], t.id), {
            onSuccess: closeModal,
        });
    };

    const closeModal = () => {
        setActionModal(null);
        clearErrors();
        reset();
    };

    const reloadTicket = () => router.reload({ only: ['ticket'], onSuccess: () => clearErrors() });

    const startTicket = () =>
        router.post(
            route('admin.tickets.start', t.id),
            {},
            {
                onError: (startErrors) =>
                    toast({
                        title: 'Tiket gagal dimulai.',
                        description: Object.values(startErrors)[0],
                        variant: 'danger',
                    }),
            },
        );

    // Server-side keys (conflict, expected_*) are not part of the form's data shape.
    const serverErrors: Record<string, string | undefined> = errors;
    const staleError =
        serverErrors.conflict ??
        serverErrors.expected_assigned_to ??
        serverErrors.expected_updated_at;

    const uploadAttachment = (e: FormEvent) => {
        e.preventDefault();
        if (!attachmentFile || uploading) return;

        const formData = new FormData();
        formData.append('file', attachmentFile);

        setUploading(true);
        setUploadPercentage(0);
        router.post(route('admin.tickets.attachments.store', t.id), formData, {
            forceFormData: true,
            onProgress: (event) => setUploadPercentage(event?.percentage ?? 0),
            onSuccess: () => {
                setAttachmentFile(null);
                toast({ title: 'Lampiran berhasil diunggah.', variant: 'success' });
            },
            onError: () =>
                toast({
                    title: 'Lampiran gagal diunggah.',
                    description: 'Periksa jenis berkas yang diizinkan dan ukuran maksimal 10 MB.',
                    variant: 'danger',
                }),
            onFinish: () => setUploading(false),
        });
    };

    return (
        <AdminLayout title={t.title}>
            <div className="space-y-6">
                <PageHeader
                    title={t.title}
                    subtitle={t.code}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.tickets.index')}>Kembali</Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Rincian</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                                <Detail label="Kategori">{t.category?.name ?? '-'}</Detail>
                                <Detail label="Sumber">
                                    <EnumBadge map={TICKET_SOURCE} value={t.source} />
                                </Detail>
                                <Detail label="Pelanggan">{t.customer?.name ?? '-'}</Detail>
                                <Detail label="Langganan">{t.subscription?.code ?? '-'}</Detail>
                                <Detail label="Aset jaringan">
                                    {t.network_asset?.name ?? '-'}
                                </Detail>
                                <Detail label="Lokasi">{t.location?.name ?? '-'}</Detail>
                                <Detail label="Petugas">{t.assignee?.name ?? '-'}</Detail>
                                {t.description && (
                                    <div className="md:col-span-2">
                                        <Detail label="Deskripsi">{t.description}</Detail>
                                    </div>
                                )}
                                {t.resolution_note && (
                                    <div className="md:col-span-2">
                                        <Detail label="Catatan penyelesaian">
                                            {t.resolution_note}
                                        </Detail>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-3 text-sm">
                                <Detail label="Kode">{t.code}</Detail>
                                <Detail label="Status">
                                    <EnumBadge map={TICKET_STATUS} value={t.status} />
                                </Detail>
                                <Detail label="Prioritas">
                                    <EnumBadge map={PRIORITY} value={t.priority} />
                                </Detail>
                                <Detail label="Target respons">
                                    {t.response_deadline
                                        ? formatDateTime(t.response_deadline, company)
                                        : 'Tanpa target respons'}
                                    {t.response_deadline && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            ({sourceLabel})
                                        </span>
                                    )}
                                    {t.is_response_breached && (
                                        <Badge variant="destructive" className="ml-2">
                                            Respons terlambat
                                        </Badge>
                                    )}
                                </Detail>
                                <Detail label="Respons pertama">
                                    {formatDateTime(t.first_response_at, company)}
                                </Detail>
                                <Detail label="Target penyelesaian">
                                    {formatDateTime(t.sla_deadline, company)}
                                    {t.sla_deadline && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            ({sourceLabel})
                                        </span>
                                    )}
                                    {t.is_sla_breached && (
                                        <Badge variant="destructive" className="ml-2">
                                            Penyelesaian terlambat
                                        </Badge>
                                    )}
                                </Detail>
                                <Detail label="Check-in">{checkInText(t.check_in)}</Detail>
                                <Detail label="Diselesaikan">
                                    {formatDateTime(t.resolved_at, company)}
                                </Detail>
                                <Detail label="Ditutup">
                                    {formatDateTime(t.closed_at, company)}
                                </Detail>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Tindakan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {t.status === 'open' && (
                                <Button type="button" onClick={() => setActionModal('assign')}>
                                    Tugaskan
                                </Button>
                            )}
                            {t.can?.start && (
                                <Button type="button" onClick={startTicket}>
                                    Mulai
                                </Button>
                            )}
                            {(t.status === 'on_progress' || t.status === 'assigned') && (
                                <Button type="button" onClick={() => setActionModal('resolve')}>
                                    Selesaikan
                                </Button>
                            )}
                            {t.can?.close && (
                                <Button type="button" onClick={() => setActionModal('close')}>
                                    Tutup
                                </Button>
                            )}
                            {(t.status === 'on_progress' || t.status === 'assigned') &&
                                !t.resolution_note && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setActionModal('spawnSpk')}
                                    >
                                        Buat SPK
                                    </Button>
                                )}
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal('comment')}
                            >
                                Tambah komentar
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Komentar</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {(t.comments ?? []).length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Belum ada komentar. Pakai tombol Tambah komentar untuk mencatat
                                penanganan.
                            </p>
                        )}
                        {(t.comments ?? []).map((c) => (
                            <div key={c.id} className="rounded-lg border border-border p-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-medium">
                                        {c.author?.name ?? 'Tidak dikenal'}
                                    </p>
                                    {c.is_internal && <Badge variant="brand">Internal</Badge>}
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">{c.body}</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {formatDateTime(c.created_at, company)}
                                </p>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Lampiran</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <form
                            onSubmit={uploadAttachment}
                            className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-start"
                        >
                            <FileUpload
                                label="Lampiran"
                                value={attachmentFile}
                                onChange={setAttachmentFile}
                                acceptedFileTypes={[
                                    'image/jpeg',
                                    'image/png',
                                    'application/pdf',
                                    'text/plain',
                                    'application/msword',
                                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                ]}
                                required
                            />
                            <div className="self-end">
                                <Button
                                    type="submit"
                                    disabled={!attachmentFile || uploading}
                                    loading={uploading}
                                >
                                    Unggah
                                </Button>
                            </div>
                            {uploading && (
                                <Progress
                                    className="sm:col-span-2"
                                    label="Mengunggah lampiran"
                                    value={uploadPercentage}
                                />
                            )}
                        </form>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(t.attachments ?? []).map((a) => (
                                <div key={a.id} className="rounded-lg border border-border p-3">
                                    <p className="text-sm font-medium">
                                        {a.original_name ?? a.file_path}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDateTime(a.created_at, company)}
                                    </p>
                                    <MediaPreview media={a} />
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Modal
                    open={actionModal !== null}
                    onClose={closeModal}
                    title={actionModal ? ACTION_TITLE[actionModal] : ''}
                >
                    <form onSubmit={doAction} className="space-y-4">
                        {staleError && (
                            <Alert variant="destructive">
                                <AlertTitle>Tiket sudah berubah</AlertTitle>
                                <AlertDescription className="space-y-2">
                                    <p>{staleError}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={reloadTicket}
                                    >
                                        Muat ulang tiket
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}
                        {actionModal === 'assign' && (
                            <SearchSelect
                                label="Petugas"
                                value={data.handler_id}
                                onChange={(value) => setData('handler_id', value)}
                                options={handlers.map((handler) => ({
                                    value: String(handler.id),
                                    label: handler.name,
                                    description: handler.email,
                                }))}
                                placeholder="Cari petugas"
                                emptyText="Tidak ada petugas yang cocok."
                                error={errors.handler_id}
                                required
                            />
                        )}
                        {actionModal === 'resolve' && (
                            <Textarea
                                label="Catatan penyelesaian"
                                value={data.resolution_note}
                                onChange={(e) => setData('resolution_note', e.target.value)}
                                error={errors.resolution_note}
                                required
                                rows={3}
                            />
                        )}
                        {actionModal === 'comment' && (
                            <>
                                <Textarea
                                    label="Komentar"
                                    value={data.body}
                                    onChange={(e) => setData('body', e.target.value)}
                                    error={errors.body}
                                    required
                                    rows={3}
                                />
                                <NativeSelect
                                    label="Terlihat oleh"
                                    value={data.is_internal ? 'internal' : 'public'}
                                    onChange={(e) =>
                                        setData('is_internal', e.target.value === 'internal')
                                    }
                                    options={[
                                        { value: 'public', label: 'Semua, termasuk pelanggan' },
                                        { value: 'internal', label: 'Internal saja' },
                                    ]}
                                />
                            </>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={closeModal}>
                                Batal
                            </Button>
                            <Button type="submit" loading={processing}>
                                {actionModal ? ACTION_CONFIRM[actionModal] : 'Simpan'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
