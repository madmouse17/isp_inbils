import { useState } from 'react';
import { AlertCircle, Check, ShieldAlert, X } from 'lucide-react';
import { Link, router } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle, Button, EnumBadge, Textarea } from '@/Components/ui';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';
import { useCompany } from '@/hooks/useCompany';
import { formatDate, formatDateTime } from '@/lib/format';
import { APPROVAL_TYPE, PRIORITY, WORK_ITEM_DOMAIN, WORK_ITEM_STATUS } from '@/lib/statuses';
import type { ApprovalInboxItem } from '@/types';
import { formatAge } from '../../MyWork/components/utils';
import { getItemCompositeId } from './utils';

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
    const company = useCompany();
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
                        ? 'Gagal menyetujui permintaan barang. Status mungkin sudah berubah atau ada konflik.'
                        : 'Gagal menyetujui SPK. Status mungkin sudah berubah atau ada konflik.');
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
                        ? 'Gagal menolak permintaan barang. Status mungkin sudah berubah atau ada konflik.'
                        : 'Gagal menolak SPK. Status mungkin sudah berubah atau ada konflik.');
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
                            ? `Tinjau permintaan barang ${item.code}`
                            : `Tinjau SPK ${item.code}`}
                    </SheetTitle>
                    <EnumBadge map={WORK_ITEM_DOMAIN} value={item.domain} />
                    <EnumBadge map={WORK_ITEM_STATUS} value={item.status} />
                    <EnumBadge map={PRIORITY} value={item.priority} />
                    <EnumBadge map={APPROVAL_TYPE} value={item.type} />
                </div>
                <SheetDescription className="text-sm text-muted-foreground">
                    {isInventory
                        ? 'Periksa rincian sebelum menyetujui atau menolak permintaan barang.'
                        : 'Periksa rincian sebelum menyetujui atau menolak SPK.'}
                </SheetDescription>
            </SheetHeader>

            <div
                className="space-y-5 flex-1 overflow-y-auto p-6"
                tabIndex={0}
                aria-label={
                    isInventory ? 'Detail peninjauan permintaan barang' : 'Detail peninjauan SPK'
                }
            >
                <div aria-live="polite" className="sr-only">
                    {submitting
                        ? 'Sedang memproses tindakan...'
                        : errorMessage
                          ? `Terjadi kesalahan: ${errorMessage}`
                          : ''}
                </div>

                {errorMessage && (
                    <Alert variant="destructive" className="space-y-2">
                        <AlertCircle className="h-5 w-5" />
                        <AlertTitle>Gagal memproses tindakan</AlertTitle>
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
                                    {isReloading ? 'Memuat ulang...' : 'Muat ulang antrean'}
                                </Button>
                            </div>
                        )}
                    </Alert>
                )}

                {!isActionable && (
                    <Alert variant="warning">
                        <ShieldAlert className="h-5 w-5" />
                        <AlertTitle>Perlu penyetuju lain</AlertTitle>
                        <AlertDescription>
                            {isInventory
                                ? 'Anda tercatat sebagai pemohon permintaan barang ini. Persetujuan harus dilakukan oleh peninjau lain agar pemisahan tugas terjaga.'
                                : 'Anda tercatat sebagai pembuat atau teknisi yang ditugaskan pada SPK ini. Persetujuan harus dilakukan oleh peninjau lain agar pemisahan tugas terjaga.'}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="space-y-3 rounded-lg border border-border bg-card p-4">
                    <h4 className="text-sm font-semibold text-muted-foreground">
                        {isInventory ? 'Informasi permintaan barang' : 'Informasi SPK'}
                    </h4>
                    <div>
                        <p className="text-sm font-semibold text-foreground">{item.title}</p>
                    </div>
                    <dl className="grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt className="text-muted-foreground">Pemohon:</dt>
                            <dd className="font-medium text-foreground">
                                {item.requester?.name ?? '-'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Status:</dt>
                            <dd>
                                <EnumBadge map={WORK_ITEM_STATUS} value={item.status} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Pengajuan:</dt>
                            <dd className="font-medium text-foreground">
                                {formatAge(item.age_seconds)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tanggal pengajuan:</dt>
                            <dd className="font-medium text-foreground">
                                {formatDateTime(item.submitted_at, company)}
                            </dd>
                        </div>
                        <div className="col-span-2">
                            <dt className="text-muted-foreground">
                                {isInventory ? 'Tanggal dibutuhkan:' : 'Jadwal pelaksanaan:'}
                            </dt>
                            <dd className="font-medium text-foreground">
                                {formatDate(item.scheduled_date, company)}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="space-y-2 rounded-lg border border-border bg-card p-4">
                    <h4 className="text-sm font-semibold text-muted-foreground">
                        Dampak material dan penggunaan barang
                    </h4>
                    <p className="text-sm font-medium text-foreground">{item.summary.label}</p>
                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                        <span>
                            Jenis item:{' '}
                            <strong className="text-foreground">{item.summary.count}</strong>
                        </span>
                        <span>
                            Total kuantitas:{' '}
                            <strong className="text-foreground">{item.summary.quantity}</strong>{' '}
                            unit
                        </span>
                    </div>
                    <p className="pt-1 text-xs text-muted-foreground">
                        {isInventory
                            ? 'Persetujuan akan mencadangkan stok di gudang dan mengubah status menjadi Disetujui.'
                            : 'Persetujuan akan memproses alokasi stok material dan memperbarui status SPK secara permanen.'}
                    </p>
                </div>

                {isInventory && item.actions.approve && !isRejecting && (
                    <div className="space-y-2 rounded-lg border border-border bg-card p-4">
                        <label
                            htmlFor="inv-approve-note"
                            className="text-sm font-semibold text-muted-foreground"
                        >
                            Catatan persetujuan (opsional)
                        </label>
                        <Textarea
                            id="inv-approve-note"
                            rows={2}
                            value={approveNote}
                            onChange={(e) => setApproveNote(e.target.value)}
                            placeholder="Contoh: disetujui sesuai kuota proyek atau stok gudang..."
                            disabled={submitting || isStale || isReloading}
                            className="text-xs"
                        />
                    </div>
                )}

                {isRejecting && (
                    <div className="space-y-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4">
                        <div className="flex items-center justify-between">
                            <h4 className="text-sm font-semibold text-destructive-text">
                                {isInventory
                                    ? 'Konfirmasi penolakan permintaan barang'
                                    : 'Konfirmasi penolakan SPK'}
                            </h4>
                            <span className="text-xs text-muted-foreground">
                                {rejectReason.length}/500 karakter
                            </span>
                        </div>
                        <Textarea
                            id="spk-reject-reason"
                            label="Alasan penolakan"
                            required
                            autoFocus
                            value={rejectReason}
                            onChange={(e) => {
                                setRejectReason(e.target.value);
                                if (rejectReasonError) setRejectReasonError(null);
                            }}
                            placeholder={
                                isInventory
                                    ? 'Tulis alasan spesifik mengapa permintaan barang ini ditolak...'
                                    : 'Tulis alasan spesifik mengapa SPK ini ditolak...'
                            }
                            error={rejectReasonError ?? undefined}
                            disabled={submitting || isStale || isReloading}
                            rows={3}
                        />
                        <p className="text-xs text-muted-foreground">
                            Alasan ini tersimpan di riwayat audit dan diteruskan kepada pemohon.
                        </p>
                    </div>
                )}
            </div>

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
                                    Konfirmasi tolak
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
