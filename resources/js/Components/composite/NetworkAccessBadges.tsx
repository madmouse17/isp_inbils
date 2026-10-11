import { formatDateTime } from '@/lib/format';
import type {
    NasDevice,
    NetworkAccount,
    NetworkDesiredState,
    NetworkServiceType,
    NetworkSyncStatus,
} from '@/types/network-asset';
import { ToneBadge, type BadgeTone } from './ToneBadge';

/*
 * Status words shared by the NAS router pages and the network account page. The text carries the
 * state; the colour only reinforces it, so no badge relies on colour alone.
 */

export const serviceTypeLabel: Record<NetworkServiceType, string> = {
    pppoe: 'PPPoE',
    hotspot: 'Hotspot',
};

/** What the router should hold for a subscription, derived by the server. */
export const desiredStateExplanation: Record<NetworkDesiredState, string> = {
    enabled: 'The account is on the router with the speed profile of the package.',
    isolated:
        'The account is moved to the isolir profile of the router and its session is dropped.',
    absent: 'The account is removed from the router.',
};

export function NasStatusBadge({
    nas,
}: {
    nas: Pick<NasDevice, 'unreachable_since' | 'last_checked_at'>;
}) {
    if (nas.unreachable_since) {
        return (
            <ToneBadge tone="danger">
                Unreachable since {formatDateTime(nas.unreachable_since)}
            </ToneBadge>
        );
    }

    if (!nas.last_checked_at) {
        return <ToneBadge tone="secondary">Never checked</ToneBadge>;
    }

    return <ToneBadge tone="success">Online</ToneBadge>;
}

export function WritesBadge({ enabled }: { enabled: boolean }) {
    return enabled ? (
        <ToneBadge tone="warning">Writes on</ToneBadge>
    ) : (
        <ToneBadge tone="secondary">Writes off</ToneBadge>
    );
}

const syncStatuses: Record<NetworkSyncStatus, { tone: BadgeTone; label: string }> = {
    pending: { tone: 'warning', label: 'Pending' },
    synced: { tone: 'success', label: 'Synced' },
    failed: { tone: 'danger', label: 'Failed' },
};

export function SyncStatusBadge({ status }: { status: NetworkSyncStatus }) {
    const { tone, label } = syncStatuses[status];

    return <ToneBadge tone={tone}>{label}</ToneBadge>;
}

const desiredStates: Record<NetworkDesiredState, { tone: BadgeTone; label: string }> = {
    enabled: { tone: 'success', label: 'Enabled' },
    isolated: { tone: 'warning', label: 'Isolated' },
    absent: { tone: 'secondary', label: 'Absent' },
};

export function DesiredStateBadge({ state }: { state: NetworkDesiredState }) {
    const { tone, label } = desiredStates[state];

    return <ToneBadge tone={tone}>{label}</ToneBadge>;
}

export function OnlineBadge({ account }: { account: Pick<NetworkAccount, 'online'> }) {
    return account.online ? (
        <ToneBadge tone="success">Online</ToneBadge>
    ) : (
        <ToneBadge tone="secondary">Offline</ToneBadge>
    );
}
