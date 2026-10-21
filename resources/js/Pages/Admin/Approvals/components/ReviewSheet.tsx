import { useEffect, useState } from 'react';
import { AlertCircle, Check, ShieldAlert, X } from 'lucide-react';
import { Link, router } from '@inertiajs/react';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    EmptyCell,
    EnumBadge,
    Skeleton,
    Textarea,
} from '@/Components/ui';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
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
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { APPROVAL_TYPE, PRIORITY, WORK_ITEM_DOMAIN, WORK_ITEM_STATUS } from '@/lib/statuses';
import { cn } from '@/lib/utils';
import type { ApprovalInboxItem } from '@/types';
import { checkInText, type CheckInRecord } from '../../Tickets/components/checkIn';
import { formatAge } from '../../MyWork/components/utils';
import { getItemCompositeId, materialQuantity } from './utils';

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

/** The detail of one row, loaded when its sheet opens (`ApprovalInboxQuery::review`). */
interface ReviewData {
    /** What approving does, one line each: the confirmation shows them. Empty for an inventory request. */
    impact: string[];
    /** At most three photos of the work, to judge it without opening the SPK. */
    evidence: { id: string; url: string; caption: string | null }[];
    technician: { name: string } | null;
    check_in: CheckInRecord | null;
    /** The materials the SPK used, or the ones a request asks for with the stock that could still be reserved. */
    items: { name: string; quantity: string; unit: string | null; available: string | null }[];
}

/** `ready` with null: the server holds no detail for this person. `failed`: the answer never came. */
type ReviewLoad =
    { state: 'loading' } | { state: 'failed' } | { state: 'ready'; review: ReviewData | null };

function ReviewDetails({
    isInventory,
    load,
    onRetry,
}: {
    isInventory: boolean;
    load: ReviewLoad;
    onRetry: () => void;
}) {
    return (
        <section
            aria-labelledby="review-details-title"
            aria-busy={load.state === 'loading'}
            className="space-y-3 rounded-lg border border-border bg-card p-4"
        >
            <h4 id="review-details-title" className="text-sm font-semibold text-muted-foreground">
                {isInventory ? 'Barang diminta' : 'Bukti pekerjaan'}
            </h4>

            {load.state === 'loading' && (
                <div role="status">
                    <span className="sr-only">Memuat rincian peninjauan...</span>
                    <div className="space-y-3" aria-hidden="true">
                        <Skeleton className="h-4 w-2/3" />
                        {!isInventory && (
                            <div className="grid grid-cols-3 gap-2">
                                <Skeleton className="aspect-square" />
                                <Skeleton className="aspect-square" />
                                <Skeleton className="aspect-square" />
                            </div>
                        )}
                        <Skeleton className="h-4 w-1/2" />
                    </div>
                </div>
            )}

            {load.state === 'failed' && (
                <Alert variant="warning" className="space-y-2">
                    <AlertTitle>Rincian belum bisa dimuat</AlertTitle>
                    <AlertDescription className="space-y-2">
                        <p>
                            Anda tetap bisa menyetujui atau menolak, tetapi bukti dan stok belum
                            terlihat.
                        </p>
                        <Button type="button" size="sm" variant="outline" onClick={onRetry}>
                            Coba lagi
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {load.state === 'ready' && load.review === null && (
                <p className="text-sm text-muted-foreground">
                    Rincian bukti dan stok tidak tersedia untuk akun Anda.
                </p>
            )}

            {load.state === 'ready' && load.review !== null && (
                <div className="space-y-4">
                    {!isInventory && (
                        <>
                            <dl className="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <dt className="text-muted-foreground">Teknisi:</dt>
                                    <dd className="font-medium text-foreground">
                                        {load.review.technician?.name ?? <EmptyCell />}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Check-in:</dt>
                                    <dd className="font-medium text-foreground">
                                        {checkInText(load.review.check_in)}
                                    </dd>
                                </div>
                            </dl>
                            {load.review.evidence.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada foto bukti.
                                </p>
                            ) : (
                                <ul className="grid grid-cols-3 gap-2">
                                    {load.review.evidence.map((photo, index) => (
                                        <li key={photo.id}>
                                            <a
                                                href={photo.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="block overflow-hidden rounded-md border border-border focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                                            >
                                                <img
                                                    src={photo.url}
                                                    alt={photo.caption || `Foto bukti ${index + 1}`}
                                                    loading="lazy"
                                                    className="aspect-square w-full bg-muted object-cover"
                                                />
                                                <span className="sr-only">, buka di tab baru</span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}

                    <div className="space-y-1.5">
                        {!isInventory && (
                            <h5 className="text-xs font-semibold text-muted-foreground">
                                Barang terpakai
                            </h5>
                        )}
                        {load.review.items.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {isInventory
                                    ? 'Permintaan ini belum punya barang.'
                                    : 'Belum ada barang tercatat.'}
                            </p>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {load.review.items.map((line, index) => {
                                    const unit = line.unit ? ` ${line.unit}` : '';
                                    const short =
                                        line.available !== null &&
                                        Number(line.quantity) > Number(line.available);

                                    return (
                                        <li key={`${line.name}-${index}`} className="py-1.5">
                                            <div className="flex items-baseline justify-between gap-3">
                                                <span className="min-w-0 font-medium text-foreground">
                                                    {line.name}
                                                </span>
                                                <span className="shrink-0 text-foreground">
                                                    {formatNumber(line.quantity)}
                                                    {unit}
                                                </span>
                                            </div>
                                            {line.available !== null && (
                                                <p
                                                    className={cn(
                                                        'text-xs',
                                                        short
                                                            ? 'text-destructive-text'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {short && (
                                                        <AlertCircle
                                                            className="mr-1 inline size-3.5 align-text-bottom"
                                                            aria-hidden="true"
                                                        />
                                                    )}
                                                    Tersedia: {formatNumber(line.available)}
                                                    {unit}
                                                    {short ? ', kurang dari yang diminta.' : ''}
                                                </p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}

function ReviewSheetInner({ item, onClose, onRefreshQueue }: ReviewSheetInnerProps) {
    const company = useCompany();
    const isInventory = item.domain === 'inventory_request';
    const [isRejecting, setIsRejecting] = useState(false);
    const [rejectReason, setRejectReason] = useState('');
    const [rejectReasonError, setRejectReasonError] = useState<string | null>(null);
    const [approveNote, setApproveNote] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [confirmingApprove, setConfirmingApprove] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [isStale, setIsStale] = useState(false);
    const [isReloading, setIsReloading] = useState(false);
    const compositeId = getItemCompositeId(item);
    const [reviewLoad, setReviewLoad] = useState<ReviewLoad>({ state: 'loading' });
    const [reviewAttempt, setReviewAttempt] = useState(0);

    // The detail is fetched when the sheet opens, never with the list: a partial reload that asks only for `review`.
    // The answer is kept here, not in the page props, so a sheet never shows the detail of the row opened before it.
    useEffect(() => {
        let active = true;
        let answered = false;

        router.reload({
            only: ['review'],
            data: { review: compositeId },
            preserveUrl: true,
            onSuccess: (page) => {
                answered = true;
                if (active) {
                    setReviewLoad({
                        state: 'ready',
                        review: (page.props.review as ReviewData | null | undefined) ?? null,
                    });
                }
            },
            onFinish: () => {
                if (active && !answered) setReviewLoad({ state: 'failed' });
            },
        });

        return () => {
            active = false;
        };
    }, [compositeId, reviewAttempt]);

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
            onFinish: () => {
                setSubmitting(false);
                setConfirmingApprove(false);
            },
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

                <ReviewDetails
                    isInventory={isInventory}
                    load={reviewLoad}
                    onRetry={() => {
                        setReviewLoad({ state: 'loading' });
                        setReviewAttempt((attempt) => attempt + 1);
                    }}
                />

                <div className="space-y-2 rounded-lg border border-border bg-card p-4">
                    <h4 className="text-sm font-semibold text-muted-foreground">
                        Dampak material dan penggunaan barang
                    </h4>
                    <p className="text-xs text-muted-foreground">
                        Jenis item:{' '}
                        <strong className="text-foreground">{item.summary.count}</strong>
                    </p>
                    {/* Each product in its own unit: 18 m of cable and 2 pcs of connectors are not 20 of anything. */}
                    <ul className="space-y-1 text-sm">
                        {(item.summary.materials ?? []).map((material, index) => (
                            <li
                                key={`${material.product}-${index}`}
                                className="flex items-baseline justify-between gap-3"
                            >
                                <span className="min-w-0 font-medium break-words text-foreground">
                                    {material.product}
                                </span>
                                <span className="shrink-0 text-foreground tabular-nums">
                                    {materialQuantity(material)}
                                </span>
                            </li>
                        ))}
                    </ul>
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
                                        onClick={() => setConfirmingApprove(true)}
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
                                        variant="outline"
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

            <ConfirmDialog
                open={confirmingApprove}
                title={`Setujui pengajuan ${item.code}?`}
                description={
                    isInventory
                        ? 'Permintaan barang disetujui dan masuk antrean gudang.'
                        : 'Pekerjaan dan penggunaan material SPK disahkan permanen.'
                }
                details={
                    reviewLoad.state === 'failed'
                        ? ['Dampak persetujuan belum bisa ditampilkan.']
                        : reviewLoad.state === 'ready'
                          ? reviewLoad.review?.impact
                          : undefined
                }
                confirmLabel="Setujui"
                processing={submitting}
                onConfirm={handleApprove}
                onClose={() => setConfirmingApprove(false)}
            />
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
