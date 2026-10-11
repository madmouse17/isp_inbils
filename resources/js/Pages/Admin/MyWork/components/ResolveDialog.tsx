import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { Alert, AlertDescription, AlertTitle, Badge, Button, Textarea } from '@/Components/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import type { MyWorkItem } from '@/types';
import type { MyWorkReloadResult } from './ActionConfirmDialog';
import { getDomainLabel, getDomainVariant } from './utils';

interface ResolveDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: MyWorkItem | null;
    onSuccess?: () => void;
    onReload?: () => Promise<MyWorkReloadResult>;
}

export function ResolveDialog({
    open,
    onOpenChange,
    item,
    onSuccess,
    onReload,
}: ResolveDialogProps) {
    const [busy, setBusy] = useState(false);

    if (!item || item.domain !== 'ticket' || !item.actions.resolve) return null;

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && busy) return;
        onOpenChange(nextOpen);
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <ResolveDialogInner
                key={`${item.domain}-${item.id}-${item.updated_at ?? 'unknown'}`}
                item={item}
                onClose={() => handleOpenChange(false)}
                onSuccessClose={() => onOpenChange(false)}
                onBusyChange={setBusy}
                onSuccess={onSuccess}
                onReload={onReload}
            />
        </Dialog>
    );
}

interface ResolveDialogInnerProps {
    item: MyWorkItem;
    onClose: () => void;
    onSuccessClose: () => void;
    onBusyChange: (busy: boolean) => void;
    onSuccess?: () => void;
    onReload?: () => Promise<MyWorkReloadResult>;
}

function ResolveDialogInner({
    item,
    onClose,
    onSuccessClose,
    onBusyChange,
    onSuccess,
    onReload,
}: ResolveDialogInnerProps) {
    const [resolutionNote, setResolutionNote] = useState('');
    const [noteError, setNoteError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [isStale, setIsStale] = useState(false);
    const [isReloading, setIsReloading] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);
    const errorRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        onBusyChange(submitting || isReloading);
    }, [isReloading, onBusyChange, submitting]);

    useEffect(() => {
        if (serverError) errorRef.current?.focus();
    }, [serverError]);

    const handleReload = async () => {
        if (!onReload || isReloading || submitting) return;

        setIsReloading(true);
        setServerError(null);
        try {
            const result = await onReload();
            if (!result.success) {
                setIsStale(true);
                setServerError(
                    result.errorMessage ||
                        'Gagal memuat ulang pekerjaan. Status item belum dapat diperbarui.',
                );
                return;
            }

            if (!result.freshItem || !result.freshItem.actions.resolve) {
                onSuccessClose();
                return;
            }

            setIsStale(false);
            setServerError(null);
        } catch {
            setIsStale(true);
            setServerError('Gagal memuat ulang pekerjaan. Terjadi kesalahan jaringan.');
        } finally {
            setIsReloading(false);
        }
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting || isStale || isReloading) return;

        const trimmed = resolutionNote.trim();
        if (!trimmed) {
            setNoteError('Catatan resolusi/penanganan wajib diisi.');
            return;
        }

        setNoteError(null);
        setServerError(null);
        setSubmitting(true);

        router.post(
            route('admin.tickets.resolve', item.id),
            { resolution_note: trimmed },
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => {
                    onSuccessClose();
                    onSuccess?.();
                },
                onError: (errors) => {
                    const first = Object.values(errors)[0] as string | undefined;
                    const conflict = Boolean(errors.conflict);
                    setServerError(
                        first || 'Gagal menyelesaikan tiket. Periksa input lalu coba lagi.',
                    );
                    // Only a server-declared conflict makes the item stale. Ordinary
                    // validation errors must leave the action retryable.
                    setIsStale(conflict);
                },
            },
        );
    };

    return (
        <DialogContent className="max-h-[min(90dvh,48rem)] max-w-lg overflow-y-auto">
            <form onSubmit={handleSubmit}>
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant={getDomainVariant(item.domain)}
                            className="text-[10px] font-semibold uppercase"
                        >
                            {getDomainLabel(item.domain)}
                        </Badge>
                        <DialogTitle className="text-lg font-bold wrap-break-word">
                            Selesaikan Tiket {item.code}
                        </DialogTitle>
                    </div>
                    <DialogDescription className="text-xs text-muted-foreground">
                        Masukkan penjelasan solusi penanganan masalah pelanggan sebelum menandai
                        tiket selesai.
                    </DialogDescription>
                </DialogHeader>

                <div aria-live="polite" className="sr-only">
                    {submitting
                        ? 'Sedang menyimpan resolusi.'
                        : isReloading
                          ? 'Sedang memuat ulang pekerjaan.'
                          : isStale
                            ? 'Item pekerjaan sudah berubah. Muat ulang sebelum mencoba lagi.'
                            : ''}
                </div>

                <div className="space-y-4 mt-4 text-sm">
                    <div className="space-y-1 rounded-lg border border-border bg-muted/40 p-3 text-xs">
                        <div className="wrap-break-word">
                            <span className="font-semibold text-foreground">Judul Tiket: </span>
                            <span className="text-muted-foreground">{item.title}</span>
                        </div>
                        <div className="wrap-break-word">
                            <span className="font-semibold text-foreground">Pelanggan: </span>
                            <span className="text-muted-foreground">
                                {item.customer?.name ?? '—'}
                            </span>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Textarea
                            id="resolution-note"
                            label="Catatan Solusi / Penanganan"
                            required
                            rows={4}
                            value={resolutionNote}
                            onChange={(e) => {
                                setResolutionNote(e.target.value);
                                setNoteError(null);
                            }}
                            placeholder="Jelaskan tindakan teknis atau solusi yang telah dilakukan untuk mengatasi keluhan pelanggan..."
                            disabled={submitting || isStale || isReloading}
                            error={noteError ?? undefined}
                        />
                        <p className="text-[11px] text-muted-foreground">
                            Catatan ini akan tersimpan dalam riwayat audit tiket dan dapat ditinjau
                            saat penutupan (close).
                        </p>
                    </div>

                    {serverError && (
                        <Alert
                            ref={errorRef}
                            tabIndex={-1}
                            variant="destructive"
                            aria-live="assertive"
                        >
                            <ExclamationTriangleIcon className="h-4 w-4" />
                            <AlertTitle>
                                {isStale ? 'Item Sudah Berubah' : 'Gagal Menyimpan'}
                            </AlertTitle>
                            <AlertDescription>{serverError}</AlertDescription>
                            {isStale && onReload && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="mt-3 min-h-11"
                                    onClick={() => void handleReload()}
                                    loading={isReloading}
                                    disabled={isReloading || submitting}
                                >
                                    {isReloading ? 'Memuat Ulang...' : 'Muat Ulang Pekerjaan'}
                                </Button>
                            )}
                        </Alert>
                    )}
                </div>

                <DialogFooter className="mt-6 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={onClose}
                        disabled={submitting || isReloading}
                    >
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant="default"
                        className="min-h-11"
                        loading={submitting}
                        disabled={submitting || isStale || isReloading}
                    >
                        <CheckCircleIcon className="mr-1.5 h-4 w-4" />
                        Selesaikan Tiket
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
