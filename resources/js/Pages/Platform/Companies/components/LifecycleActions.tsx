import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { PauseCircle, PlayCircle, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui';
import {
    ConfirmActionDialog,
    type PlatformConfirmation,
} from '@/Components/composite/platform/ConfirmActionDialog';
import { formatMoney, formatNumber } from '@/lib/format';
import type {
    PlatformLifecycle,
    PlatformSuspendImpact,
    PlatformTenantProfile,
} from '@/types/platform';

type LifecycleAction = 'suspend' | 'reactivate' | 'delete' | null;

interface LifecycleActionsProps {
    tenant: PlatformTenantProfile;
    lifecycle: PlatformLifecycle;
    /** What suspending would cut off; null once the tenant is not active. */
    suspendImpact: PlatformSuspendImpact | null;
}

/** One line per thing the suspension reaches, counted by the server. Sessions are left out when the driver cannot count them. */
function impactLines(impact: PlatformSuspendImpact, currency: string): string[] {
    return [
        `${formatNumber(impact.users)} pengguna aktif tidak bisa masuk lagi.`,
        ...(impact.sessions === null
            ? []
            : [`${formatNumber(impact.sessions)} sesi yang sedang berjalan langsung berakhir.`]),
        `${formatNumber(impact.app_tokens)} token aplikasi seluler berhenti berlaku.`,
        impact.open_invoices.count === 0
            ? 'Tidak ada faktur terbuka.'
            : `${formatNumber(impact.open_invoices.count)} faktur terbuka senilai ${formatMoney(impact.open_invoices.outstanding, currency)}.`,
    ];
}

export function LifecycleActions({ tenant, lifecycle, suspendImpact }: LifecycleActionsProps) {
    const [action, setAction] = useState<LifecycleAction>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const inFlight = useRef(false);

    const submit = (confirmation: PlatformConfirmation) => {
        if (!action || inFlight.current) return;

        const url =
            action === 'suspend'
                ? route('platform.companies.suspend', tenant.id)
                : action === 'reactivate'
                  ? route('platform.companies.reactivate', tenant.id)
                  : route('platform.companies.deletion-request.store', tenant.id);

        inFlight.current = true;
        setProcessing(true);
        setErrors({});
        router.post(url, confirmation, {
            preserveScroll: true,
            preserveState: true,
            onError: (visitErrors) => setErrors(visitErrors as Record<string, string | undefined>),
            onSuccess: () => setAction(null),
            onFinish: () => {
                inFlight.current = false;
                setProcessing(false);
            },
        });
    };

    return (
        <>
            <div className="flex flex-wrap gap-2">
                {lifecycle.can_suspend ? (
                    <Button
                        type="button"
                        variant="danger"
                        leftIcon={<PauseCircle className="size-4" aria-hidden="true" />}
                        onClick={() => setAction('suspend')}
                    >
                        Tangguhkan
                    </Button>
                ) : null}
                {lifecycle.can_reactivate ? (
                    <Button
                        type="button"
                        leftIcon={<PlayCircle className="size-4" aria-hidden="true" />}
                        onClick={() => setAction('reactivate')}
                    >
                        Aktifkan kembali
                    </Button>
                ) : null}
                {!lifecycle.can_suspend ? (
                    <Button
                        type="button"
                        variant="outline"
                        leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
                        disabled={!lifecycle.can_request_deletion}
                        aria-describedby={
                            lifecycle.deletion_blockers.length > 0 ? 'deletion-blockers' : undefined
                        }
                        onClick={() => setAction('delete')}
                    >
                        Ajukan hapus
                    </Button>
                ) : null}
            </div>

            {action === 'suspend' ? (
                <ConfirmActionDialog
                    title={`Tangguhkan ${tenant.name}?`}
                    description="Perusahaan dan anak perusahaan aktif kehilangan akses. Data tetap tersimpan dan dapat diaktifkan kembali."
                    details={suspendImpact ? impactLines(suspendImpact, tenant.currency) : undefined}
                    confirmLabel="Tangguhkan perusahaan"
                    tone="danger"
                    reason
                    companyCode={tenant.code}
                    processing={processing}
                    errors={errors}
                    onConfirmed={submit}
                    onClose={() => setAction(null)}
                />
            ) : null}
            {action === 'reactivate' ? (
                <ConfirmActionDialog
                    title={`Aktifkan kembali ${tenant.name}?`}
                    description="Perusahaan yang ditangguhkan platform aktif kembali. Pengajuan hapus yang masih ada dibatalkan."
                    confirmLabel="Aktifkan kembali"
                    requirePassword={false}
                    reason
                    processing={processing}
                    errors={errors}
                    onConfirmed={submit}
                    onClose={() => setAction(null)}
                />
            ) : null}
            {action === 'delete' ? (
                <ConfirmActionDialog
                    title={`Ajukan hapus ${tenant.name}?`}
                    description="Tindakan ini hanya mencatat pengajuan. Tidak ada data yang dihapus dari panel web."
                    confirmLabel="Ajukan hapus"
                    tone="danger"
                    companyCode={tenant.code}
                    processing={processing}
                    errors={errors}
                    onConfirmed={submit}
                    onClose={() => setAction(null)}
                />
            ) : null}
        </>
    );
}
