import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Modal } from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';
import { BlockedBadge, SubscriptionBlockModal } from '@/Components/composite/NetworkAccessBadges';
import { formatDateTime } from '@/lib/format';
import type { ServiceSubscription } from '@/types/models';
import { SubscriptionNetworkPanels } from '@/Components/composite/SubscriptionNetworkPanels';
import type { DropPort, DropPortOption, StaticPool, SubscriptionIpAddress } from '@/types/ipam-odn';
import { usePermission } from '@/hooks/usePermission';

interface ShowProps extends Record<string, unknown> {
    subscription: { data: ServiceSubscription };
    ipAddresses: SubscriptionIpAddress[];
    /** Present only for holders of ipam.assign. */
    staticPools?: StaticPool[];
    dropPort: DropPort | null;
    /** Free ODP ports; delivered by a partial reload that asks for them (odn.assign only). */
    dropPortOptions?: DropPortOption[];
    can: { assignIp: boolean; assignDrop: boolean; block: boolean };
}

export default function Show({
    subscription,
    ipAddresses,
    staticPools,
    dropPort,
    dropPortOptions,
    can: { block: canBlock, ...assignable },
}: ShowProps) {
    const s = subscription.data;
    const can = usePermission();
    const [actionModal, setActionModal] = useState<'suspend' | 'terminate' | null>(null);
    const [blockOpen, setBlockOpen] = useState(false);
    const { data, setData, post, processing } = useForm({ reason: '', release_ont: false });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (actionModal === 'suspend') {
            post(route('admin.subscriptions.suspend', s.id), {
                onSuccess: () => setActionModal(null),
            });
        } else if (actionModal === 'terminate') {
            post(route('admin.subscriptions.terminate', s.id), {
                onSuccess: () => setActionModal(null),
            });
        }
    };

    const status =
        s.status === 'active'
            ? 'success'
            : s.status === 'suspended'
              ? 'warning'
              : s.status === 'terminated'
                ? 'danger'
                : 'muted';

    return (
        <AdminLayout title={`Subscription ${s.code}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Subscription ${s.code}`}
                    subtitle="Record details and related activity."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link
                                href={route(
                                    'admin.customers.subscriptions.index',
                                    Number(s.customer_id ?? 0),
                                )}
                            >
                                Back
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Package: </span>
                                {s.package?.name ?? `#${s.service_package_id}`}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Kategori paket: </span>
                                {s.package?.category?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Customer: </span>
                                {s.customer?.name ?? `#${s.customer_id}`}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    Installation Address:{' '}
                                </span>
                                {s.installation_address?.label ?? `#${s.installation_address_id}`}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Serving POP: </span>
                                {s.serving_pop?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">MRC: </span>
                                {s.mrc_amount}
                            </p>
                            <p>
                                <span className="text-muted-foreground">OTC: </span>
                                {s.otc_installation_fee}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Billing Day: </span>
                                {s.billing_day}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Contract Months: </span>
                                {s.contract_months ?? '-'}
                            </p>
                            {s.notes && (
                                <p>
                                    <span className="text-muted-foreground">Notes: </span>
                                    {s.notes}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <StatusBadge variant={status}>{s.status}</StatusBadge>
                            </p>
                            {s.blocked_at && (
                                <div className="space-y-1">
                                    <p>
                                        <span className="text-muted-foreground">Internet: </span>
                                        <BlockedBadge />
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">
                                            Block reason:{' '}
                                        </span>
                                        {s.block_reason ?? '-'}
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">Blocked by: </span>
                                        {s.blocked_by?.name ?? '-'}, {formatDateTime(s.blocked_at)}
                                    </p>
                                </div>
                            )}
                            <p>
                                <span className="text-muted-foreground">Activation Date: </span>
                                {s.activation_date ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Expiration Date: </span>
                                {s.expiration_date ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Next Invoice: </span>
                                {s.next_invoice_date ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Terminated At: </span>
                                {s.terminated_at ?? '-'}
                            </p>
                            {s.terminated_reason && (
                                <p>
                                    <span className="text-muted-foreground">
                                        Terminated Reason:{' '}
                                    </span>
                                    {s.terminated_reason}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <SubscriptionNetworkPanels
                    subscription={{
                        id: Number(s.id),
                        code: s.code ?? '',
                        status: s.status ?? '',
                        hasServingPop: s.serving_pop_id != null,
                    }}
                    ipAddresses={ipAddresses}
                    staticPools={staticPools}
                    dropPort={dropPort}
                    dropPortOptions={dropPortOptions}
                    can={assignable}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Lifecycle Actions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {s.status === 'pending' && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        post(route('admin.subscriptions.activate', s.id))
                                    }
                                >
                                    Activate
                                </Button>
                            )}
                            {s.status === 'active' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setActionModal('suspend')}
                                >
                                    Suspend
                                </Button>
                            )}
                            {s.status === 'suspended' && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        post(route('admin.subscriptions.reactivate', s.id))
                                    }
                                >
                                    Reactivate
                                </Button>
                            )}
                            {(s.status === 'active' || s.status === 'suspended') && (
                                <Button
                                    type="button"
                                    variant="danger"
                                    onClick={() => setActionModal('terminate')}
                                >
                                    Terminate
                                </Button>
                            )}
                            {canBlock && s.status !== 'terminated' && (
                                <Button
                                    type="button"
                                    variant={s.blocked_at ? 'outline' : 'danger'}
                                    onClick={() => setBlockOpen(true)}
                                >
                                    {s.blocked_at ? 'Unblock' : 'Block internet'}
                                </Button>
                            )}
                            {can('network_asset.account.view') && (
                                <Button asChild variant="outline">
                                    <Link
                                        href={route(
                                            'admin.subscriptions.network-account.show',
                                            s.id,
                                        )}
                                    >
                                        Network access
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <SubscriptionBlockModal
                    target={
                        blockOpen
                            ? {
                                  id: Number(s.id),
                                  code: s.code ?? '',
                                  status: s.status ?? '',
                                  blocked_at: s.blocked_at ?? null,
                              }
                            : null
                    }
                    onClose={() => setBlockOpen(false)}
                />

                <Modal
                    open={actionModal !== null}
                    onClose={() => setActionModal(null)}
                    title={
                        actionModal === 'suspend'
                            ? 'Suspend Subscription'
                            : 'Terminate Subscription'
                    }
                >
                    <form onSubmit={doAction} className="space-y-4">
                        <Input
                            label="Reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            required
                        />
                        {actionModal === 'terminate' && (
                            <div className="text-sm text-muted-foreground">
                                ONT release will be available in Phase 3.
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="danger" loading={processing}>
                                {actionModal === 'suspend' ? 'Suspend' : 'Terminate'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
