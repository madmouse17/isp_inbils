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

/**
 * Whether FreeRADIUS has the client row (`nas`) Inbils wrote for this router. A company without RADIUS has no
 * FreeRADIUS to write to, so its routers read as not configured instead of as a failed write.
 */
export function NasStatusBadge({
    nas,
    radiusEnabled,
}: {
    nas: Pick<NasDevice, 'radius_synced_at' | 'last_error'>;
    radiusEnabled: boolean;
}) {
    const state = !radiusEnabled
        ? 'disabled'
        : nas.radius_synced_at
          ? 'synced'
          : nas.last_error
            ? 'error'
            : 'waiting';

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

/** What a block means for the customer, whatever the router does. */
const CUSTOMER_EFFECT =
    'Tagihan tetap berjalan dan pembayaran tidak mencabut blokir. Di aplikasi pelanggan tertulis Dibatasi.';

/**
 * The dialog's explanation. `radiusEnabled` is the company's RADIUS switch: with RADIUS off nothing reaches the
 * router, so the text must not promise a disconnect. A page that does not carry the switch passes nothing and the
 * text then says nothing about the router instead of guessing.
 */
function explanationOf(
    unblocking: boolean,
    suspended: boolean,
    radiusEnabled: boolean | undefined,
): string {
    if (unblocking && suspended) {
        return 'Langganan sedang ditangguhkan, jadi layanan tetap diisolir sampai diaktifkan kembali.';
    }

    if (unblocking) {
        if (radiusEnabled === undefined) return 'Blokir internet pelanggan dicabut.';

        return radiusEnabled
            ? 'Sesi pelanggan diputus setelah router menjawab dan tersambung ulang dengan layanan normal.'
            : 'RADIUS belum dikonfigurasi. Pembukaan blokir disimpan dan dikirim ke router setelah RADIUS aktif.';
    }

    const router =
        radiusEnabled === undefined
            ? null
            : radiusEnabled
              ? 'Pelanggan diisolir. Sesi yang sedang terbuka diputus setelah router menjawab.'
              : 'RADIUS belum dikonfigurasi. Blokir disimpan dan dikirim ke router setelah RADIUS aktif.';

    return [router, CUSTOMER_EFFECT].filter(Boolean).join(' ');
}

/**
 * Block (required reason) or unblock (optional note) one subscription. Which one follows
 * `blocked_at`. Headless UI returns focus to the button that opened it.
 */
export function SubscriptionBlockModal({
    target,
    radiusEnabled,
    onClose,
}: {
    target: BlockTarget | null;
    /** The company's RADIUS switch, when the page has it (`radiusEnabled` prop of the network pages). */
    radiusEnabled?: boolean;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const unblocking = target?.blocked_at != null;

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
                    {explanationOf(unblocking, target?.status === 'suspended', radiusEnabled)}
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
