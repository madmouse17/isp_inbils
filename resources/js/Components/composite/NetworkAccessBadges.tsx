import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, EnumBadge, Modal, Textarea } from '@/Components/ui';
import { NAS_RADIUS_STATE, NETWORK_ISOLATED_BECAUSE } from '@/lib/statuses';
import type { NasDevice, NetworkDesiredState } from '@/types/network-asset';

/*
 * Status words shared by the NAS router pages and the network account page. The text carries the
 * state; the colour only reinforces it, so no badge relies on colour alone.
 */

/** What RADIUS should hold for a subscription, derived by the server. */
export const desiredStateExplanation: Record<NetworkDesiredState, string> = {
    enabled: 'Akun ada di RADIUS dengan grup kecepatan paketnya.',
    isolated: 'Akun ada di grup isolir perusahaan dan sesinya diputus.',
    absent: 'Akun dihapus dari RADIUS.',
};

/** Whether FreeRADIUS has the client row (`nas`) Inbils wrote for this router. */
export function NasStatusBadge({
    nas,
}: {
    nas: Pick<NasDevice, 'radius_synced_at' | 'last_error'>;
}) {
    const state = nas.radius_synced_at ? 'synced' : nas.last_error ? 'error' : 'waiting';

    return <EnumBadge map={NAS_RADIUS_STATE} value={state} />;
}

export function BlockedBadge() {
    return <EnumBadge map={NETWORK_ISOLATED_BECAUSE} value="blocked" />;
}

export interface BlockTarget {
    id: string;
    code: string;
    status: string;
    blocked_at: string | null;
}

/**
 * Block (required reason) or unblock (optional note) one subscription. Which one follows
 * `blocked_at`. Headless UI returns focus to the button that opened it.
 */
export function SubscriptionBlockModal({
    target,
    onClose,
}: {
    target: BlockTarget | null;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const unblocking = target?.blocked_at != null;
    const isolatedUntilReactivated = unblocking && target?.status === 'suspended';

    const close = () => {
        if (processing) return;
        setReason('');
        setError(undefined);
        onClose();
    };

    const submit = () => {
        if (!target) return;
        setProcessing(true);
        setError(undefined);
        router.post(
            route(
                unblocking ? 'admin.subscriptions.unblock' : 'admin.subscriptions.block',
                target.id,
            ),
            { reason: reason.trim() === '' ? null : reason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReason('');
                    onClose();
                },
                onError: (errors) => setError(errors.reason ?? errors.subscription),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Modal
            open={target !== null}
            onClose={close}
            title={
                target
                    ? `${unblocking ? 'Buka blokir' : 'Blokir'} internet ${target.code}?`
                    : undefined
            }
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <p className="text-sm text-muted-foreground">
                    {unblocking
                        ? isolatedUntilReactivated
                            ? 'Langganan sedang ditangguhkan, jadi layanan tetap diisolir sampai diaktifkan kembali.'
                            : 'Sesi pelanggan diputus dan layanan normal kembali saat router menyambung ulang.'
                        : 'Pelanggan diisolir dan sesinya diputus. Tagihan tetap berjalan dan pembayaran tidak mencabut blokir.'}
                </p>
                <Textarea
                    id="subscription-block-reason"
                    label={unblocking ? 'Catatan (opsional)' : 'Alasan'}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    maxLength={500}
                    required={!unblocking}
                    error={error}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={close}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant={unblocking ? 'default' : 'danger'}
                        loading={processing}
                        disabled={processing || (!unblocking && reason.trim() === '')}
                    >
                        {unblocking ? 'Buka blokir' : 'Blokir internet'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
