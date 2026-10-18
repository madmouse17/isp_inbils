import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { AssignIpDialog } from '@/Components/composite/ipam/AssignIpDialog';
import { Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import type { StaticPool, SubscriptionIpAddress } from '@/types/ipam-odn';

interface SubscriptionIpPanelProps {
    subscription: { id: string; code: string; terminated: boolean };
    addresses: SubscriptionIpAddress[];
    /** Static pools the subscription can draw from; the server sends them only to holders of ipam.assign. */
    pools: StaticPool[];
    canAssign: boolean;
}

export function SubscriptionIpPanel({
    subscription,
    addresses,
    pools,
    canAssign,
}: SubscriptionIpPanelProps) {
    const [assigning, setAssigning] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    // One address per IP version: a pool of a version already held cannot be used again.
    const usable = pools.filter((pool) => !addresses.some((held) => held.family === pool.family));
    const editable = canAssign && !subscription.terminated;

    return (
        <Card>
            <CardHeader className="sm:flex-row sm:items-start sm:justify-between">
                <CardTitle>Alamat IP</CardTitle>
                {editable && usable.length > 0 && (
                    <Button type="button" size="sm" onClick={() => setAssigning(true)}>
                        Berikan IP statis
                    </Button>
                )}
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                {addresses.length === 0 ? (
                    <p className="text-muted-foreground">Dinamis (diberikan router)</p>
                ) : (
                    <ul className="divide-y">
                        {addresses.map((address) => (
                            <li
                                key={address.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                            >
                                <div>
                                    <span className="font-mono font-medium">{address.address}</span>
                                    <span className="ml-2 text-muted-foreground">
                                        IPv{address.family}
                                        {address.prefix_cidr ? ` · ${address.prefix_cidr}` : ''}
                                    </span>
                                </div>
                                {editable && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        aria-label={`Lepas ${address.address}`}
                                        onClick={() =>
                                            setConfirm({
                                                title: `Lepas ${address.address}?`,
                                                description: `${subscription.code} kembali memakai alamat dinamis dan ${address.address} kembali ke pool-nya.`,
                                                confirmLabel: 'Lepas alamat',
                                                href: route(
                                                    'admin.ipam.addresses.destroy',
                                                    address.id,
                                                ),
                                            })
                                        }
                                    >
                                        Lepas
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {editable && pools.length === 0 && (
                    <p className="text-muted-foreground">
                        Belum ada pool statis untuk POP langganan ini. Daftarkan di{' '}
                        <Link
                            href={route('admin.ipam.prefixes.index')}
                            className="font-medium text-primary-text hover:underline"
                        >
                            Alamat IP
                        </Link>
                        .
                    </p>
                )}
            </CardContent>

            {assigning && (
                <AssignIpDialog
                    onClose={() => setAssigning(false)}
                    subscription={subscription}
                    pools={usable}
                />
            )}
            {confirm && (
                <ConfirmRequestDialog
                    key={confirm.href}
                    request={confirm}
                    onClose={() => setConfirm(null)}
                />
            )}
        </Card>
    );
}
