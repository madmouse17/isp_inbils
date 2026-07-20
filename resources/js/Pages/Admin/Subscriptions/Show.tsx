import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Modal } from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';
import type { ServiceSubscription } from '@/types/models';

interface ShowProps extends Record<string, unknown> {
    subscription: { data: ServiceSubscription };
}

export default function Show({ subscription }: ShowProps) {
    const s = subscription.data;
    const [actionModal, setActionModal] = useState<'suspend' | 'terminate' | null>(null);
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
                            type="button"
                            variant="secondary"
                            onClick={() =>
                                router.get(
                                    route('admin.customers.subscriptions.index', s.customer_id),
                                )
                            }
                        >
                            Back
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
                        </div>
                    </CardContent>
                </Card>

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
