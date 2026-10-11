import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { Alert, AlertDescription, AlertTitle, Button } from '@/Components/ui';
import type { MyWorkItem } from '@/types';
import { getDomainLabel } from './utils';

export type ActionType = 'start' | 'submit' | 'close';

export interface MyWorkReloadResult {
    success: boolean;
    freshItem?: MyWorkItem | null;
    errorMessage?: string;
}

interface ActionConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: MyWorkItem | null;
    actionType: ActionType | null;
    onSuccess?: () => void;
    onReload?: () => Promise<MyWorkReloadResult>;
}

export function ActionConfirmDialog({
    open,
    onOpenChange,
    item,
    actionType,
    onSuccess,
    onReload,
}: ActionConfirmDialogProps) {
    const [busy, setBusy] = useState(false);

    if (!item || !actionType) return null;

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && busy) return;
        onOpenChange(nextOpen);
    };

    return (
        <ActionConfirmDialogInner
            key={`${item.domain}-${item.id}-${actionType}-${item.updated_at ?? 'unknown'}`}
            open={open}
            onOpenChange={handleOpenChange}
            item={item}
            actionType={actionType}
            onSuccessClose={() => onOpenChange(false)}
            onBusyChange={setBusy}
            onSuccess={onSuccess}
            onReload={onReload}
        />
    );
}

interface ActionConfirmDialogInnerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: MyWorkItem;
    actionType: ActionType;
    onSuccessClose: () => void;
    onBusyChange: (busy: boolean) => void;
    onSuccess?: () => void;
    onReload?: () => Promise<MyWorkReloadResult>;
}

function ActionConfirmDialogInner({
    open,
    onOpenChange,
    item,
    actionType,
    onSuccessClose,
    onBusyChange,
    onSuccess,
    onReload,
}: ActionConfirmDialogInnerProps) {
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

    const getDialogContent = () => {
        switch (actionType) {
            case 'start':
                return {
                    title: `Mulai Pengerjaan ${getDomainLabel(item.domain)} ${item.code}?`,
                    description: `Status akan diperbarui menjadi 'Sedang Dikerjakan' (${item.domain === 'ticket' ? 'on_progress' : 'in_progress'}). Waktu mulai pengerjaan akan tercatat di sistem audit.`,
                    confirmLabel: 'Mulai Kerja',
                    route:
                        item.domain === 'ticket'
                            ? route('admin.tickets.start', item.id)
                            : route('admin.spk.start', item.id),
                };
            case 'submit':
                return {
                    title: `Ajukan Peninjauan SPK ${item.code}?`,
                    description:
                        'Pekerjaan lapangan dan deklarasi material akan diajukan ke antrean Approval. Anda tidak dapat mengubah item pekerjaan setelah diajukan hingga proses review selesai.',
                    confirmLabel: 'Ajukan Review',
                    route: route('admin.spk.submit', item.id),
                };
            case 'close':
                return {
                    title: `Tutup Tiket ${item.code}?`,
                    description:
                        'Tiket yang telah terselesaikan akan ditutup secara permanen. Pastikan kendala pelanggan telah tuntas terselesaikan.',
                    confirmLabel: 'Tutup Tiket',
                    route: route('admin.tickets.close', item.id),
                };
        }
    };

    const config = getDialogContent();

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

            if (!result.freshItem || !result.freshItem.actions[actionType]) {
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

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && (submitting || isReloading)) return;
        onOpenChange(nextOpen);
    };

    const handleConfirm = () => {
        if (!item.actions[actionType] || submitting || isStale || isReloading) return;

        setSubmitting(true);
        setServerError(null);

        router.post(
            config.route,
            {},
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => {
                    onBusyChange(false);
                    onSuccessClose();
                    onSuccess?.();
                },
                onError: (errors) => {
                    const first = Object.values(errors)[0] as string | undefined;
                    const conflict = Boolean(errors.conflict);
                    setServerError(
                        first || 'Gagal memproses tindakan. Periksa input lalu coba lagi.',
                    );
                    // Only a server-declared conflict makes the item stale. Ordinary
                    // validation errors must leave the action retryable.
                    setIsStale(conflict);
                },
            },
        );
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!nextOpen && (submitting || isReloading)) return;
                handleOpenChange(nextOpen);
            }}
        >
            <AlertDialogContent className="max-h-[min(90dvh,40rem)] max-w-md overflow-y-auto">
                <AlertDialogHeader>
                    <AlertDialogTitle className="break-words">{config.title}</AlertDialogTitle>
                    <AlertDialogDescription className="text-xs">
                        {config.description}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div aria-live="polite" className="sr-only">
                    {submitting
                        ? 'Sedang memproses tindakan.'
                        : isReloading
                          ? 'Sedang memuat ulang pekerjaan.'
                          : isStale
                            ? 'Item pekerjaan sudah berubah. Muat ulang sebelum mencoba lagi.'
                            : ''}
                </div>

                {serverError && (
                    <Alert
                        ref={errorRef}
                        tabIndex={-1}
                        variant="destructive"
                        className="mt-2 text-xs"
                        aria-live="assertive"
                    >
                        <AlertTitle>
                            {isStale ? 'Item Sudah Berubah' : 'Terjadi Kesalahan'}
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

                <AlertDialogFooter className="mt-4 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting || isReloading}
                    >
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="default"
                        className="min-h-11"
                        loading={submitting}
                        disabled={submitting || isStale || isReloading || !item.actions[actionType]}
                        onClick={handleConfirm}
                    >
                        {config.confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
