import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge, Button, Modal, Textarea } from '@/Components/ui';
import type {
    NasDevice,
    NetworkDesiredState,
    NetworkServiceType,
    NetworkSyncStatus,
} from '@/types/network-asset';
import type { BadgeVariant } from '@/Components/ui/Badge';

/*
 * Status words shared by the NAS router pages and the network account page. The text carries the
 * state; the colour only reinforces it, so no badge relies on colour alone.
 */

export const serviceTypeLabel: Record<NetworkServiceType, string> = {
    pppoe: 'PPPoE',
    hotspot: 'Hotspot',
};

/** What RADIUS should hold for a subscription, derived by the server. */
export const desiredStateExplanation: Record<NetworkDesiredState, string> = {
    enabled: 'The account is in RADIUS with the speed group of the package.',
    isolated: 'The account is in the isolir group of the company and its session is disconnected.',
    absent: 'The account is removed from RADIUS.',
};

/** Whether FreeRADIUS has the client row (`nas`) Inbils wrote for this router. */
export function NasStatusBadge({
    nas,
}: {
    nas: Pick<NasDevice, 'radius_synced_at' | 'last_error'>;
}) {
    if (nas.radius_synced_at) {
        return <Badge variant="success">Written to RADIUS</Badge>;
    }

    if (nas.last_error) {
        return <Badge variant="danger">Not written</Badge>;
    }

    return <Badge variant="warning">Waiting to be written</Badge>;
}

const syncStatuses: Record<NetworkSyncStatus, { tone: BadgeVariant; label: string }> = {
    pending: { tone: 'warning', label: 'Pending' },
    synced: { tone: 'success', label: 'Synced' },
    failed: { tone: 'danger', label: 'Failed' },
};

export function SyncStatusBadge({ status }: { status: NetworkSyncStatus }) {
    const { tone, label } = syncStatuses[status];

    return <Badge variant={tone}>{label}</Badge>;
}

const desiredStates: Record<NetworkDesiredState, { tone: BadgeVariant; label: string }> = {
    enabled: { tone: 'success', label: 'Enabled' },
    isolated: { tone: 'warning', label: 'Isolated' },
    absent: { tone: 'neutral', label: 'Absent' },
};

export function DesiredStateBadge({ state }: { state: NetworkDesiredState }) {
    const { tone, label } = desiredStates[state];

    return <Badge variant={tone}>{label}</Badge>;
}

export function OnlineBadge({ online }: { online: boolean }) {
    return online ? (
        <Badge variant="success">Online</Badge>
    ) : (
        <Badge variant="neutral">Offline</Badge>
    );
}

export function BlockedBadge() {
    return <Badge variant="danger">Blocked</Badge>;
}

export interface BlockTarget {
    id: number;
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
                    ? `${unblocking ? 'Unblock' : 'Block'} internet of ${target.code}?`
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
                            ? 'The subscription is suspended, so the service stays isolated until it is reactivated.'
                            : 'The subscriber is disconnected and gets the normal service back when the router redials.'
                        : 'The subscriber is isolated and disconnected. Billing goes on and a payment does not lift the block.'}
                </p>
                <Textarea
                    id="subscription-block-reason"
                    label={unblocking ? 'Note (optional)' : 'Reason'}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    maxLength={500}
                    required={!unblocking}
                    error={error}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant={unblocking ? 'default' : 'danger'}
                        loading={processing}
                        disabled={processing || (!unblocking && reason.trim() === '')}
                    >
                        {unblocking ? 'Unblock' : 'Block internet'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
