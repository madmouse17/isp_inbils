import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ExclamationTriangleIcon, UserIcon } from '@heroicons/react/24/outline';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { Alert, AlertDescription, AlertTitle, Badge, Button } from '@/Components/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import type { DispatchItem, QueueUserOption } from '@/types';
import {
    formatAge,
    formatDate,
    getDomainLabel,
    getDomainVariant,
    getItemImpact,
    getItemWhyNeedAction,
    getNextStatusInfo,
    getPriorityLabel,
    getPriorityVariant,
} from './utils';

interface AssignDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: DispatchItem | null;
    handlers: QueueUserOption[];
    technicians: QueueUserOption[];
    onSuccess?: () => void;
    onReloadQueue?: () => Promise<DispatchReloadResult>;
}

export interface DispatchReloadResult {
    success: boolean;
    freshItem?: DispatchItem | null;
    errorMessage?: string;
}

export function AssignDialog({
    open,
    onOpenChange,
    item,
    handlers,
    technicians,
    onSuccess,
    onReloadQueue,
}: AssignDialogProps) {
    const [busy, setBusy] = useState(false);

    if (!item) return null;

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && busy) return;
        onOpenChange(nextOpen);
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <AssignDialogInner
                key={`${item.domain}-${item.id}-${item.updated_at ?? 'unknown'}`}
                item={item}
                handlers={handlers}
                technicians={technicians}
                onClose={() => handleOpenChange(false)}
                onSuccessClose={() => onOpenChange(false)}
                onBusyChange={setBusy}
                onSuccess={onSuccess}
                onReloadQueue={onReloadQueue}
            />
        </Dialog>
    );
}

interface AssignDialogInnerProps {
    item: DispatchItem;
    handlers: QueueUserOption[];
    technicians: QueueUserOption[];
    onClose: () => void;
    onSuccessClose: () => void;
    onBusyChange: (busy: boolean) => void;
    onSuccess?: () => void;
    onReloadQueue?: () => Promise<DispatchReloadResult>;
}

function AssignDialogInner({
    item,
    handlers,
    technicians,
    onClose,
    onSuccessClose,
    onBusyChange,
    onSuccess,
    onReloadQueue,
}: AssignDialogInnerProps) {
    const isTicket = item.domain === 'ticket';
    const candidateList = isTicket ? handlers : technicians;
    const roleLabel = isTicket ? 'Petugas Tiket (Handler)' : 'Teknisi Lapangan';

    const [selectedAssigneeId, setSelectedAssigneeId] = useState<string>(
        item.assigned_to ? String(item.assigned_to) : '',
    );
    const [assigneeError, setAssigneeError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);
    const [isConflict, setIsConflict] = useState(false);
    const [isReloading, setIsReloading] = useState(false);
    const [reloadError, setReloadError] = useState<string | null>(null);
    const submitGuardRef = useRef(false);
    const statusRef = useRef<HTMLParagraphElement>(null);

    useEffect(() => {
        if (isConflict || reloadError) statusRef.current?.focus();
    }, [isConflict, reloadError]);

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting || submitGuardRef.current || isConflict || isReloading) return;

        const assigneeIdNum = Number(selectedAssigneeId);
        if (!assigneeIdNum || assigneeIdNum <= 0) {
            setAssigneeError(`Silakan pilih ${roleLabel} penerima penugasan.`);
            return;
        }

        setAssigneeError(null);
        setServerError(null);
        setReloadError(null);
        setIsConflict(false);
        submitGuardRef.current = true;
        setSubmitting(true);
        onBusyChange(true);

        const payload = {
            domain: item.domain,
            id: item.id,
            assignee_id: assigneeIdNum,
            expected_assigned_to: item.assigned_to ?? null,
            expected_updated_at: item.updated_at ?? null,
        };

        router.post(route('admin.dispatch.assign'), payload, {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => {
                submitGuardRef.current = false;
                setSubmitting(false);
                onBusyChange(false);
            },
            onSuccess: () => {
                onBusyChange(false);
                onSuccessClose();
                onSuccess?.();
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0] as string | undefined;
                setServerError(first || 'Gagal menyimpan penugasan. Periksa input lalu coba lagi.');
                setIsConflict(Boolean(errors.conflict));
                setReloadError(null);
            },
        });
    };

    const handleReload = async () => {
        if (!onReloadQueue || isReloading || submitting) return;

        setIsReloading(true);
        onBusyChange(true);
        setReloadError(null);
        try {
            const result = await onReloadQueue();
            if (!result.success) {
                setReloadError(
                    result.errorMessage ||
                        'Gagal memuat ulang antrean. Status antrean belum dapat diperbarui.',
                );
                return;
            }

            if (!result.freshItem) {
                onBusyChange(false);
                onSuccessClose();
                return;
            }

            setIsConflict(false);
            setServerError(null);
        } catch {
            setReloadError('Gagal memuat ulang antrean. Terjadi kesalahan jaringan.');
        } finally {
            setIsReloading(false);
            onBusyChange(false);
        }
    };

    return (
        <DialogContent className="max-h-[calc(100dvh-2rem)] max-w-lg overflow-y-auto">
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
                            Tugaskan {getDomainLabel(item.domain)} {item.code}
                        </DialogTitle>
                    </div>
                    <DialogDescription className="text-xs text-muted-foreground">
                        Alokasikan tanggung jawab eksekusi operasional kepada petugas atau teknisi
                        yang sesuai.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 mt-4 text-sm">
                    <p className="sr-only" aria-live="polite">
                        {submitting
                            ? 'Sedang menyimpan penugasan.'
                            : isReloading
                              ? 'Sedang memuat ulang antrean.'
                              : ''}
                    </p>
                    {/* Informative Context Panel */}
                    <div className="space-y-2 rounded-lg border border-border bg-muted/40 p-3.5">
                        <div>
                            <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Judul / Permintaan:
                            </span>
                            <p className="font-medium text-foreground">{item.title}</p>
                        </div>

                        <div className="grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span className="text-muted-foreground">Pemohon: </span>
                                <span className="font-medium text-foreground">
                                    {item.requester?.name ?? '—'}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">Prioritas: </span>
                                <Badge
                                    variant={getPriorityVariant(item.priority)}
                                    className="ml-1 text-[10px] font-semibold uppercase"
                                >
                                    {getPriorityLabel(item.priority)}
                                </Badge>
                            </div>
                            <div>
                                <span className="text-muted-foreground">Umur Antrean: </span>
                                <span className="font-medium text-foreground">
                                    {formatAge(item.age_seconds)}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    {isTicket ? 'Batas SLA: ' : 'Jadwal Kerja: '}
                                </span>
                                <span className="font-medium text-foreground">
                                    {isTicket
                                        ? formatDate(item.due_at ?? item.summary?.sla_deadline)
                                        : formatDate(
                                              item.scheduled_date ?? item.summary?.scheduled_date,
                                          )}
                                </span>
                            </div>
                        </div>

                        {item.sla_breached && (
                            <div className="flex items-center gap-1.5 text-xs font-semibold text-destructive">
                                <ExclamationTriangleIcon className="h-4 w-4 shrink-0" />
                                <span>Peringatan: SLA telah terlampaui!</span>
                            </div>
                        )}
                    </div>

                    {/* Operational Summary & Impact */}
                    <div className="space-y-1 text-xs">
                        <p className="text-muted-foreground">
                            <span className="font-semibold text-foreground">Alasan Tindakan: </span>
                            {getItemWhyNeedAction(item)}
                        </p>
                        <p className="text-muted-foreground">
                            <span className="font-semibold text-foreground">Dampak: </span>
                            {getItemImpact(item)}
                        </p>
                        <p className="text-muted-foreground">
                            <span className="font-semibold text-foreground">Alur Berikutnya: </span>
                            {getNextStatusInfo(item)}
                        </p>
                    </div>

                    {/* Assignee Selection Field */}
                    <div className="space-y-1.5 pt-1">
                        <NativeSelect
                            id="assignee-select"
                            label={`Pilih ${roleLabel}`}
                            required
                            value={selectedAssigneeId}
                            onChange={(e) => {
                                setSelectedAssigneeId(e.target.value);
                                setAssigneeError(null);
                            }}
                            error={assigneeError ?? undefined}
                            options={[
                                { value: '', label: `-- Pilih ${roleLabel} --` },
                                ...candidateList.map((c) => ({
                                    value: String(c.id),
                                    label: `${c.name} (${c.email})`,
                                })),
                            ]}
                            disabled={submitting || isConflict || isReloading}
                        />
                        {candidateList.length === 0 && (
                            <p className="text-xs text-amber-600 dark:text-amber-400">
                                Tidak ada {roleLabel.toLowerCase()} aktif yang memiliki izin
                                penanganan domain ini.
                            </p>
                        )}
                    </div>

                    {/* Error / Conflict Alert */}
                    {serverError && (
                        <Alert
                            variant="destructive"
                            className="mt-2"
                            role="alert"
                            aria-live="assertive"
                        >
                            <ExclamationTriangleIcon className="h-4 w-4" />
                            <AlertTitle>
                                {isConflict ? 'Konflik Konkurensi' : 'Terjadi Kesalahan'}
                            </AlertTitle>
                            <AlertDescription>{serverError}</AlertDescription>
                        </Alert>
                    )}
                    {(isConflict || reloadError) && (
                        <p
                            ref={statusRef}
                            tabIndex={-1}
                            role="status"
                            aria-live="assertive"
                            className="sr-only"
                        >
                            {reloadError ||
                                'Data penugasan sudah berubah. Muat ulang antrean sebelum menyimpan kembali.'}
                        </p>
                    )}
                    {reloadError && (
                        <Alert variant="warning" className="mt-2">
                            <ExclamationTriangleIcon className="h-4 w-4" />
                            <AlertTitle>Muat ulang belum berhasil</AlertTitle>
                            <AlertDescription>{reloadError}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter className="mt-6 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={submitting || isReloading}
                        className="min-h-11"
                    >
                        Batal
                    </Button>
                    {isConflict ? (
                        <Button
                            type="button"
                            variant="default"
                            onClick={() => {
                                void handleReload();
                            }}
                            loading={isReloading}
                            disabled={isReloading || submitting || !onReloadQueue}
                            className="min-h-11"
                        >
                            {isReloading ? 'Memuat Ulang...' : 'Muat Ulang Antrean'}
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            variant="default"
                            loading={submitting}
                            disabled={
                                submitting ||
                                isConflict ||
                                isReloading ||
                                candidateList.length === 0
                            }
                            className="min-h-11"
                        >
                            <UserIcon className="mr-1.5 h-4 w-4" />
                            Simpan Penugasan
                        </Button>
                    )}
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
