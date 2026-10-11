import { DropPortPanel } from '@/Components/composite/odn/DropPortPanel';
import { SubscriptionIpPanel } from '@/Components/composite/ipam/SubscriptionIpPanel';
import { usePermission } from '@/hooks/usePermission';
import type { DropPort, DropPortOption, StaticPool, SubscriptionIpAddress } from '@/types/ipam-odn';

interface SubscriptionNetworkPanelsProps {
    subscription: { id: number; code: string; status: string; hasServingPop: boolean };
    ipAddresses: SubscriptionIpAddress[];
    /** Present only for holders of ipam.assign. */
    staticPools: StaticPool[] | undefined;
    dropPort: DropPort | null;
    /** Free ports; present only after a partial reload asked for them. */
    dropPortOptions: DropPortOption[] | undefined;
    can: { assignIp: boolean; assignDrop: boolean };
}

/** The IP address and drop port panels of the subscription page, each shown only to those who may view it. */
export function SubscriptionNetworkPanels({
    subscription,
    ipAddresses,
    staticPools,
    dropPort,
    dropPortOptions,
    can,
}: SubscriptionNetworkPanelsProps) {
    const allowed = usePermission();
    const showIp = allowed('ipam.view');
    const showDrop = allowed('odn.view');
    const terminated = subscription.status === 'terminated';

    if (!showIp && !showDrop) return null;

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            {showIp && (
                <SubscriptionIpPanel
                    subscription={{ id: subscription.id, code: subscription.code, terminated }}
                    addresses={ipAddresses}
                    pools={staticPools ?? []}
                    canAssign={can.assignIp}
                />
            )}
            {showDrop && (
                <DropPortPanel
                    subscription={{
                        id: subscription.id,
                        code: subscription.code,
                        terminated,
                        hasServingPop: subscription.hasServingPop,
                    }}
                    dropPort={dropPort}
                    canAssign={can.assignDrop}
                    options={dropPortOptions}
                />
            )}
        </div>
    );
}
