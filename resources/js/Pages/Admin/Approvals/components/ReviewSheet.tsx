import { useState } from 'react';
import { AlertCircle, Check, ShieldAlert, X } from 'lucide-react';
import { Link, router } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle, Badge, Button, Textarea } from '@/Components/ui';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';
import type { ApprovalInboxItem } from '@/types';
import {
    formatAge,
    getDomainLabel,
    getDomainVariant,
    getItemCompositeId,
    getPriorityLabel,
    getPriorityVariant,
    getStatusLabel,
    getStatusVariant,
} from './utils';

export interface RefreshQueueResult {
    success: boolean;
    freshItem?: ApprovalInboxItem | null;
    errorMessage?: string;
}

interface ReviewSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: ApprovalInboxItem | null;
    onRefreshQueue?: () => Promise<RefreshQueueResult> | void;
}

interface ReviewSheetInnerProps {
    item: ApprovalInboxItem;
    onClose: () => void;
    onRefreshQueue?: () => Promise<RefreshQueueResult> | void;
}

function ReviewSheetInner({ item, onClose, onRefreshQueue }: ReviewSheetInnerProps) {
    const isInventory = item.domain === 'inventory_request';
    const [isRejecting, setIsRejecting] = useState(false);
    const [rejectReason, setRejectReason] = useState('');
    const [rejectReasonError, setRejectReasonError] = useState<string | null>(null);
    const [approveNote, setApproveNote] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [isStale, setIsStale] = useState(false);
    const [isReloading, setIsReloading] = useState(false);

    const isActionable = item.actions.approve || item.actions.reject;

    const handleReloadQueue = async () => {
        if (!onRefreshQueue || isReloading || submitting) return;

        setIsReloading(true);

        try {
            const res = await onRefreshQueue();
            if (res && typeof res === 'object' && 'success' in res) {
                if (res.success) {
                    if (
                        !res.freshItem ||
                        (!res.freshItem.actions.approve && !res.freshItem.actions.reject)
                    ) {
                        onClose();
                    } else {
                        setIsStale(false);
                        setErrorMessage(null);
                        setIsRejecting(false);
                        setRejectReason('');
                        setRejectReasonError(null);
                    }
                } else {
                    setIsStale(true);
                    setErrorMessage(
                        res.errorMessage ||
                            'Gagal memuat ulang antrean. Status antrean belum dapat diperbarui.',
                    );
                }
            }
        } catch {
            setIsStale(true);
            setErrorMessage('Gagal memuat ulang antrean. Terjadi kesalahan jaringan.');
        } finally {
            setIsReloading(false);
        }
    };

    const handleApprove = () => {
        if (!item.actions.approve || submitting || isStale || isReloading) return;

        setSubmitting(true);
        setErrorMessage(null);
        setIsStale(false);

        const routeName = isInventory ? 'admin.inventory.requests.approve' : 'admin.spk.approve';
        const payload =
            isInventory && approveNote.trim() ? { approval_note: approveNote.trim() } : {};

        router.post(route(routeName, item.id), payload, {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: () => {
                onClose();
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string | undefined;
                const msg =
                    firstError ||
                    (isInventory
                        ? 'Gagal menyetujui permintaan barang. Status mungkin telah berubah atau terjadi konflik.'
                        : 'Gagal menyetujui SPK. Status mungkin telah berubah atau terjadi konflik.');
                setErrorMessage(msg);
                setIsStale(true);
            },
        });
    };

    const handleReject = () => {
        if (!item.actions.reject || submitting || isStale || isReloading) return;

        const reason = rejectReason.trim();
        if (!reason) {
            setRejectReasonError('Alasan penolakan wajib diisi.');
            return;
        }

        if (isInventory && reason.length < 3) {
            setRejectReasonError('Alasan penolakan minimal 3 karakter.');
            return;
        }

        if (reason.length > 500) {
            setRejectReasonError('Alasan penolakan maksimal 500 karakter.');
            return;
        }

        setSubmitting(true);
        setErrorMessage(null);
        setIsStale(false);

        const routeName = isInventory ? 'admin.inventory.requests.reject' : 'admin.spk.reject';
        const payload = isInventory ? { rejection_reason: reason } : { reason };

        router.post(route(routeName, item.id), payload, {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: () => {
                onClose();
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string | undefined;
                const msg =
                    firstError ||
                    (isInventory
                        ? 'Gagal menolak permintaan barang. Status mungkin telah berubah atau terjadi konflik.'
                        : 'Gagal menolak SPK. Status mungkin telah berubah atau terjadi konflik.');
                setErrorMessage(msg);
                setIsStale(true);
            },
        });
    };

    return (
        <div className="flex h-full flex-col">
            <SheetHeader className="border-b border-border p-6 pr-12 text-left">
                <div className="flex flex-wrap items-center gap-2">
                    <SheetTitle className="text-xl font-bold">
                        {isInventory
                            ? `Tinjau Permintaan Barang ${item.code}`
                            : `Tinjau SPK ${item.code}`}
                    </SheetTitle>
                    <Badge variant={getDomainVariant(item.domain)}>
                        {getDomainLabel(item.domain)}
                    </Badge>
                    <Badge variant={getStatusVariant(item.status)}>
                        {getStatusLabel(item.status)}
                    </Badge>
                    <Badge variant={getPriorityVariant(item.priority)}>
                        {getPriorityLabel(item.priority)}
                    </Badge>
                    <Badge variant="outline">{item.type}</Badge>
                </div>
                <SheetDescription className="text-sm text-muted-foreground">
                    {isInventory
                        ? 'Periksa rincian sebelum menyetujui atau menolak permintaan material dalam antrean.'
                        : 'Periksa rincian sebelum menyetujui atau menolak SPK dalam antrean review.'}
                </SheetDescription>
            </SheetHeader>

            <div
                className="space-y-5 flex-1 overflow-y-auto p-6"
                tabIndex={0}
                aria-label={
                    isInventory ? 'Detail Peninjauan Permintaan Barang' : 'Detail Peninjauan SPK'
                }
            >
                {/* Live status announcements for screen readers */}
                <div aria-live="polite" className="sr-only">
                    {submitting
                        ? 'Sedang memproses tindakan...'
                        : errorMessage
                          ? `Terjadi kesalahan: ${errorMessage}`
                          : ''}
                </div>

                {/* Stale or Conflict Error Alert */}
                {errorMessage && (
                    <Alert variant="destructive" className="space-y-2">
                        <AlertCircle className="h-5 w-5" />
                        <AlertTitle>Gagal Memproses Tindakan</AlertTitle>
                        <AlertDescription>{errorMessage}</AlertDescription>
                        {isStale && onRefreshQueue && (
                            <div className="pt-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        void handleReloadQueue();
                                    }}
                                    loading={isReloading}
                                    disabled={isReloading || submitting}
                                >
                                    {isReloading ? 'Memuat Ulang...' : 'Muat Ulang Antrean'}
                                </Button>
                            </div>
                        )}
                    </Alert>
                )}

                {/* Maker-checker Warning */}
                {!isActionable && (
                    <Alert variant="warning">
                        <ShieldAlert className="h-5 w-5" />
                        <AlertTitle>Aturan Maker-Checker</AlertTitle>
                        <AlertDescription>
                            {isInventory
                                ? 'Anda tercatat sebagai pemohon permintaan barang ini. Sesuai prinsip maker-checker dan tata kelola sistem, persetujuan harus dilakukan oleh reviewer independen.'
                                : 'Anda tercatat sebagai pembuat atau teknisi yang ditugaskan pada SPK ini. Sesuai kebijakan tata kelola sistem, persetujuan harus dilakukan oleh reviewer independen.'}
                        </AlertDescription>
                    </Alert>
                )}

                {/* General Information Card */}
                <div className="space-y-3 rounded-lg border border-border bg-card p-4 shadow-xs">
                    <h4 className="text-sm font-semibold text-muted-foreground">
                        {isInventory ? 'Informasi Permintaan Barang' : 'Informasi SPK'}
                    </h4>
                    <div>
                        <p className="text-sm font-semibold text-foreground">{item.title}</p>
                    </div>
                    <div className="grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <span className="text-muted-foreground">Pemohon:</span>
                            <p className="font-medium text-foreground">
                                {item.requester?.name ?? `#${item.requester?.id ?? '-'}`}
                            </p>
                        </div>
                        <div>
                            <span className="text-muted-foreground">Status:</span>
                            <p className="font-medium text-foreground">
                                {getStatusLabel(item.status)}
                            </p>
                        </div>
                        <div>
                            <span className="text-muted-foreground">Pengajuan:</span>
                            <p className="font-medium text-foreground">
                                {formatAge(item.age_seconds)}
                            </p>
                        </div>
                        <div>
                            <span className="text-muted-foreground">Tanggal Pengajuan:</span>
                            <p className="font-medium text-foreground">
                                {item.submitted_at
                                    ? new Date(item.submitted_at).toLocaleString('id-ID')
                                    : '-'}
                            </p>
                        </div>
                        <div className="col-span-2">
                            <span className="text-muted-foreground">
                                {isInventory ? 'Tanggal Dibutuhkan:' : 'Jadwal Pelaksanaan:'}
                            </span>
                            <p className="font-medium text-foreground">
                                {item.scheduled_date || '-'}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Impact & Material Summary Card */}
                <div className="space-y-2 rounded-lg border border-border bg-card p-4 shadow-xs">
                    <h4 className="text-sm font-semibold text-muted-foreground">
                        Dampak Material & Penggunaan Barang
                    </h4>
                    <p className="text-sm font-medium text-foreground">{item.summary.label}</p>
                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                        <span>
                            Jenis Item:{' '}
                            <strong className="text-foreground">{item.summary.count}</strong>
                        </span>
                        <span>&middot;</span>
                        <span>
                            Total Kuantitas:{' '}
                            <strong className="text-foreground">{item.summary.quantity}</strong>{' '}
                            unit
                        </span>
                    </div>
                    <p className="pt-1 text-xs text-muted-foreground italic">
                        {isInventory
                            ? '* Persetujuan akan mereservasi stok material di gudang dan mengubah status menjadi Disetujui (Reservasi).'
                            : '* Persetujuan akan memproses alokasi stok material dan memperbarui status SPK secara permanen.'}
                    </p>
                </div>

                {/* Optional Approval Note for Inventory Request */}
                {isInventory && item.actions.approve && !isRejecting && (
                    <div className="space-y-2 rounded-lg border border-border bg-card p-4 shadow-xs">
                        <label
                            htmlFor="inv-approve-note"
                            className="text-sm font-semibold text-muted-foreground"
                        >
                            Catatan Persetujuan (Opsional)
                        </label>
                        <Textarea
                            id="inv-approve-note"
                            rows={2}
                            value={approveNote}
                            onChange={(e) => setApproveNote(e.target.value)}
                            placeholder="Contoh: Disetujui sesuai kuota proyek atau stok gudang..."
                            disabled={submitting || isStale || isReloading}
                            className="text-xs"
                        />
                    </div>
                )}

                {/* Reject Reason Form Section */}
                {isRejecting && (
                    <div className="space-y-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4">
                        <div className="flex items-center justify-between">
                            <h4 className="text-sm font-semibold text-destructive-text">
                                {isInventory
                                    ? 'Konfirmasi Penolakan Permintaan Barang'
                                    : 'Konfirmasi Penolakan SPK'}
                            </h4>
                            <span className="text-xs text-muted-foreground">
                                {rejectReason.length}/500 karakter
                            </span>
                        </div>
                        <Textarea
                            id="spk-reject-reason"
                            label="Alasan Penolakan"
                            required
                            autoFocus
                            value={rejectReason}
                            onChange={(e) => {
                                setRejectReason(e.target.value);
                                if (rejectReasonError) setRejectReasonError(null);
                            }}
                            placeholder={
                                isInventory
                                    ? 'Tuliskan alasan spesifik mengapa permintaan barang ini ditolak...'
                                    : 'Tuliskan alasan spesifik mengapa SPK ini ditolak...'
                            }
                            error={rejectReasonError ?? undefined}
                            disabled={submitting || isStale || isReloading}
                            rows={3}
                        />
                        <p className="text-xs text-muted-foreground">
                            Alasan ini akan tersimpan dalam riwayat audit dan diteruskan kepada
                            pemohon.
                        </p>
                    </div>
                )}
            </div>

            {/* Footer Actions */}
            <SheetFooter className="border-t border-border bg-muted/20 p-4">
                <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        {item.actions.view && item.detail_url && (
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="w-full sm:w-auto"
                            >
                                <Link href={item.detail_url}>Lihat detail</Link>
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {isRejecting ? (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setIsRejecting(false);
                                        setRejectReasonError(null);
                                    }}
                                    disabled={submitting || isReloading}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={handleReject}
                                    loading={submitting}
                                    disabled={
                                        submitting || isStale || isReloading || !rejectReason.trim()
                                    }
                                    leftIcon={<X className="h-4 w-4" />}
                                >
                                    Konfirmasi Tolak
                                </Button>
                            </>
                        ) : (
                            <>
                                {item.actions.reject && (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        onClick={() => setIsRejecting(true)}
                                        disabled={submitting || isStale || isReloading}
                                        leftIcon={<X className="h-4 w-4" />}
                                    >
                                        Tolak
                                    </Button>
                                )}
                                {item.actions.approve && (
                                    <Button
                                        type="button"
                                        variant="primary"
                                        size="sm"
                                        onClick={handleApprove}
                                        loading={submitting}
                                        disabled={submitting || isStale || isReloading}
                                        leftIcon={<Check className="h-4 w-4" />}
                                    >
                                        Setujui
                                    </Button>
                                )}
                                <SheetClose asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={submitting || isReloading}
                                    >
                                        Tutup
                                    </Button>
                                </SheetClose>
                            </>
                        )}
                    </div>
                </div>
            </SheetFooter>
        </div>
    );
}

export function ReviewSheet({ open, onOpenChange, item, onRefreshQueue }: ReviewSheetProps) {
    if (!item) {
        return null;
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col gap-0 p-0 motion-reduce:transition-none sm:max-w-lg md:max-w-xl"
            >
                <ReviewSheetInner
                    key={getItemCompositeId(item)}
                    item={item}
                    onClose={() => onOpenChange(false)}
                    onRefreshQueue={onRefreshQueue}
                />
            </SheetContent>
        </Sheet>
    );
}
